<?php

declare (strict_types=1);

namespace MakinaCorpus\QueryBuilder\Writer;

use MakinaCorpus\QueryBuilder\Expression;
use MakinaCorpus\QueryBuilder\SqlString;
use MakinaCorpus\QueryBuilder\Where;
use MakinaCorpus\QueryBuilder\Error\QueryBuilderError;
use MakinaCorpus\QueryBuilder\Escaper\Escaper;
use MakinaCorpus\QueryBuilder\Expression\Aliased;
use MakinaCorpus\QueryBuilder\Expression\ArrayValue;
use MakinaCorpus\QueryBuilder\Expression\Between;
use MakinaCorpus\QueryBuilder\Expression\Cast;
use MakinaCorpus\QueryBuilder\Expression\ColumnName;
use MakinaCorpus\QueryBuilder\Expression\Comparison;
use MakinaCorpus\QueryBuilder\Expression\ConstantTable;
use MakinaCorpus\QueryBuilder\Expression\Identifier;
use MakinaCorpus\QueryBuilder\Expression\Not;
use MakinaCorpus\QueryBuilder\Expression\NullValue;
use MakinaCorpus\QueryBuilder\Expression\Raw;
use MakinaCorpus\QueryBuilder\Expression\Row;
use MakinaCorpus\QueryBuilder\Expression\SimilarTo;
use MakinaCorpus\QueryBuilder\Expression\TableName;
use MakinaCorpus\QueryBuilder\Expression\Value;
use MakinaCorpus\QueryBuilder\Expression\WithAlias;
use MakinaCorpus\QueryBuilder\Platform\Escaper\StandardEscaper;
use MakinaCorpus\QueryBuilder\Query\Delete;
use MakinaCorpus\QueryBuilder\Query\Insert;
use MakinaCorpus\QueryBuilder\Query\Merge;
use MakinaCorpus\QueryBuilder\Query\Query;
use MakinaCorpus\QueryBuilder\Query\RawQuery;
use MakinaCorpus\QueryBuilder\Query\Select;
use MakinaCorpus\QueryBuilder\Query\Update;
use MakinaCorpus\QueryBuilder\Query\Partial\JoinStatement;
use MakinaCorpus\QueryBuilder\Query\Partial\SelectColumn;
use MakinaCorpus\QueryBuilder\Query\Partial\WithStatement;

/**
 * Standard SQL query formatter: this implementation conforms as much as it
 * can to SQL-92 standard, and higher revisions for some functions.
 *
 * Please note that the main target is PostgreSQL, and PostgreSQL has an
 * excellent SQL-92|1999|2003|2006|2008|2011 standard support, almost
 * everything in here except MERGE queries is supported by PostgreSQL.
 *
 * We could have override the CastExpression formatting for PostgreSQL using
 * its ::TYPE shorthand, but since that is is only a legacy syntax shorthand
 * and that the standard CAST(value AS type) expression may require less
 * parenthesis in some cases, it's simply eaiser to keep the CAST() syntax.
 *
 * All methods starting with "format" do handle a known Expression class,
 * whereas all methods starting with "do" will handle an internal behaviour.
 */
class Writer
{
    private string $matchParametersRegex;
    protected Escaper $escaper;

    public function __construct(?Escaper $escaper = null)
    {
        $this->escaper = $escaper ?? new StandardEscaper();
        $this->buildParameterRegex();
    }

    /**
     * Create SQL text from given expression.
     */
    public function prepare(string|Expression|SqlString $sql): SqlString
    {
        if ($sql instanceof SqlString) {
            return $sql;
        }

        $identifier = null;

        if (\is_string($sql)) {
            $sql = new Raw($sql);
        } else {
            // if with identifier
            //   then $identifier = $sql->getIdentifier();
        }

        $context = new WriterContext();
        $rawSql = $this->format($sql, $context);

        return new SqlString($rawSql, $context->getArgumentBag(), $identifier);
    }

    /**
     * {@inheritdoc}
     */
    protected function format(Expression $expression, WriterContext $context, bool $enforceParenthesis = false): string
    {
        $ret = match (\get_class($expression)) {
            Aliased::class => $this->formatAliased($expression, $context),
            ArrayValue::class => $this->formatArrayValue($expression, $context),
            Between::class => $this->formatBetween($expression, $context),
            Cast::class => $this->formatCast($expression, $context),
            ColumnName::class => $this->formatIdentifier($expression, $context),
            ConstantTable::class => $this->formatConstantTable($expression, $context),
            Comparison::class => $this->formatComparison($expression, $context),
            Delete::class => $this->formatDelete($expression, $context),
            Identifier::class => $this->formatIdentifier($expression, $context),
            Insert::class => $this->formatInsert($expression, $context),
            Merge::class => $this->formatMerge($expression, $context),
            Not::class => $this->formatNot($expression, $context),
            NullValue::class => $this->formatNullValue($expression, $context),
            Raw::class => $this->formatRaw($expression, $context),
            RawQuery::class => $this->formatRawQuery($expression, $context),
            Row::class => $this->formatRow($expression, $context),
            Select::class => $this->formatSelect($expression, $context),
            SimilarTo::class => $this->formatSimilarTo($expression, $context),
            TableName:: class => $this->formatIdentifier($expression, $context),
            Update::class => $this->formatUpdate($expression, $context),
            Value::class => $this->formatValue($expression, $context),
            Where::class => $this->formatWhere($expression, $context),
            default => throw new QueryBuilderError(\sprintf("Unexpected expression object type: %s", \get_class($expression))),
        };

        // Working with Aliased special case, we need to write parenthesis
        // depending upon the decorated expression, not the Aliased one.
        if (!$expression instanceof Aliased && $expression instanceof WithAlias && ($alias = $expression->getAlias())) {
            if ($this->expressionRequiresParenthesis($expression)) {
                return '(' . $ret . ') as ' . $this->escaper->escapeIdentifier($alias);
            }
            return $ret . ' as ' . $this->escaper->escapeIdentifier($alias);
        }

        if ($enforceParenthesis && $this->expressionRequiresParenthesis($expression)) {
            return '(' . $ret . ')';
        }

        return $ret;
    }

    /**
     * Does expression requires parenthesis when used inside another expression.
     */
    protected function expressionRequiresParenthesis(Expression $expression): bool
    {
        return match (\get_class($expression)) {
            ConstantTable::class => true,
            RawQuery::class => true,
            Select::class => true,
            Where::class => true,
            default => false,
        };
    }

    /**
     * Converts all typed placeholders in the query and replace them with the
     * correct placeholder syntax. It matches ? or ?::TYPE syntaxes, outside of
     * SQL dialect escape sequences, everything else will be left as-is.
     *
     * Real conversion is done later in the runner implementation, when it
     * reconciles the user given arguments with the type information found while
     * formating or parsing the SQL.
     */
    protected function parseExpression(Raw|RawQuery $expression, WriterContext $context): string
    {
        $asString = $expression->getString();
        $values = $expression->getArguments();

        if (!$values && !\str_contains($asString, '?')) {
            // Performance shortcut for expressions containing no arguments.
            return $asString;
        }

        // See https://stackoverflow.com/a/3735908 for the  starting
        // sequence explaination, the rest should be comprehensible.
        $localIndex = -1;
        return \preg_replace_callback(
            $this->matchParametersRegex,
            function ($matches) use (&$localIndex, $values, $context) {
                $match  = $matches[0];

                if ('??' === $match) {
                    return $this->escaper->unescapePlaceholderChar();
                }
                if ('?' !== $match[0]) {
                    return $match;
                }

                $localIndex++;
                $value = $values[$localIndex] ?? null;

                // Pure user sugar candy: when a value is an array, convert it
                // to a constant row automatically in order to automatically
                // expand:
                //     WHERE foo IN ?
                // into the follow form:
                //     WHERE foo IN (?, ?, ...)
                if (\is_array($value)) {
                    $value = new Row($value);
                }
                if ($value instanceof Expression) {
                    return $this->format($value, $context);
                } else {
                    $index = $context->append($value, empty($matches[6]) ? null : $matches[6]);

                    return $this->escaper->writePlaceholder($index);
                }
            },
            $asString
        );
    }

    /**
     * Format a single set clause (update queries).
     */
    protected function doFormatUpdateSetItem(WriterContext $context, string $columnName, string|Expression $expression): string
    {
        $columnString = $this->escaper->escapeIdentifier($columnName);

        if ($expression instanceof Expression) {
            return $columnString . ' = ' . $this->format($expression, $context, true);
        }
        return $columnString . ' = ' . $this->escaper->escapeLiteral($expression);
    }

    /**
     * Format all set clauses (update queries).
     *
     * @param string[]|Expression[] $columns
     *   Keys are column names, values are strings or Expression instances
     */
    protected function doFormatUpdateSet(WriterContext $context, array $columns): string
    {
        $inner = '';
        foreach ($columns as $column => $value) {
            if ($inner) {
                $inner .= ",\n";
            }
            $inner .= $this->doFormatUpdateSetItem($context, $column, $value);
        }
        return $inner;
    }

    /**
     * Format projection for a single select column or statement.
     */
    protected function doFormatSelectItem(WriterContext $context, SelectColumn $column): string
    {
        $expression = $column->getExpression();
        $output = $this->format($expression, $context, true);

        // Using a ROW() requires the ROW keyword in order to avoid
        // SQL language ambiguities. All other cases are dealt with
        // the format() method enforcing parenthesis.
        if ($expression instanceof Row) {
            $output = 'row' . $output;
        }

        // We cannot alias columns with a numeric identifier;
        // aliasing with the same string as the column name
        // makes no sense either.
        $alias = $column->getAlias();
        if ($alias && !\is_numeric($alias)) {
            $alias = $this->escaper->escapeIdentifier($alias);
            if ($alias !== $output) {
                return $output . ' as ' . $alias;
            }
        }

        return $output;
    }

    /**
     * Format SELECT columns.
     *
     * @param SelectColumn[] $columns
     */
    protected function doFormatSelect(WriterContext $context, array $columns): string
    {
        if (!$columns) {
            return '*';
        }

        return \implode(
            ",\n",
            \array_map(
                fn ($column) => $this->doFormatSelectItem($context, $column),
                $columns,
            )
        );
    }

    /**
     * Format the whole projection.
     *
     * @param array $return
     *   Each column is an array that must contain:
     *     - 0: string or Statement: column name or SQL statement
     *     - 1: column alias, can be empty or null for no aliasing
     */
    protected function doFormatReturning(WriterContext $context, array $return): string
    {
        return $this->doFormatSelect($context, $return);
    }

    /**
     * Format a single order by.
     *
     * @param int $order
     *   Query::ORDER_* constant.
     * @param int $null
     *   Query::NULL_* constant.
     */
    protected function doFormatOrderByItem(WriterContext $context, string|Expression $column, int $order, int $null): string
    {
        $column = $this->format($column, $context);

        if (Query::ORDER_ASC === $order) {
            $orderStr = 'asc';
        } else {
            $orderStr = 'desc';
        }

        return $column . ' ' . $orderStr . match ($null) {
            Query::NULL_FIRST => ' nulls first',
            Query::NULL_LAST => ' nulls last',
            default => '',
        };
    }

    /**
     * Format the whole order by clause.
     *
     * @todo Convert $orders items to an Order class.
     *
     * @param array $orders
     *   Each order is an array that must contain:
     *     - 0: Expression
     *     - 1: Query::ORDER_* constant
     *     - 2: Query::NULL_* constant
     */
    protected function doFormatOrderBy(WriterContext $context, array $orders): string
    {
        if (!$orders) {
            return '';
        }

        $output = [];

        foreach ($orders as $data) {
            list($column, $order, $null) = $data;
            $output[] = $this->doFormatOrderByItem($context, $column, $order, $null);
        }

        return "order by " . \implode(", ", $output);
    }

    /**
     * Format the whole group by clause.
     *
     * @param Expression[] $groups
     *   Array of column names or aliases.
     */
    protected function doFormatGroupBy(WriterContext $context, array $groups): string
    {
        if (!$groups) {
            return '';
        }

        $output = [];
        foreach ($groups as $group) {
            $output[] = $this->format($group, $context, true);
        }

        return "group by " . \implode(", ", $output);
    }

    /**
     * Format a single join statement.
     */
    protected function doFormatJoinItem(WriterContext $context, JoinStatement $join): string
    {
        $prefix = match ($mode = $join->getJoinMode()) {
            Query::JOIN_NATURAL => 'natural join',
            Query::JOIN_LEFT => 'left outer join',
            Query::JOIN_LEFT_OUTER => 'left outer join',
            Query::JOIN_RIGHT => 'right outer join',
            Query::JOIN_RIGHT_OUTER => 'right outer join',
            Query::JOIN_INNER => 'inner join',
            default => $mode,
        };

        $condition = $join->getCondition();

        if ($condition->isEmpty()) {
            return $prefix . ' ' . $this->format($join->getTable(), $context, true);
        }
        return $prefix . ' ' . $this->format($join->getTable(), $context, true) . ' on (' . $this->format($condition, $context, false) . ')';
    }

    /**
     * Format all join statements.
     *
     * @param JoinStatement[] $join
     */
    protected function doFormatJoin(
        WriterContext $context,
        array $join,
        bool $transformFirstJoinAsFrom = false,
        ?string $fromPrefix = null,
        Query $query = null
    ): string {
        if (!$join) {
            return '';
        }

        $output = [];

        if ($transformFirstJoinAsFrom) {
            $first = \array_shift($join);
            \assert($first instanceof JoinStatement);

            $mode = $first->getJoinMode();
            $table = $first->getTable();
            $condition = $first->getCondition();

            // First join must be an inner join, there is no choice, and first join
            // condition will become a where clause in the global query instead
            if (!\in_array($mode, [Query::JOIN_INNER, Query::JOIN_NATURAL])) {
                throw new QueryBuilderError("First join in an update query must be inner or natural, it will serve as the first FROM or USING table.");
            }

            if ($fromPrefix) {
                $output[] = $fromPrefix . ' ' . $this->format($table, $context, true);
            } else {
                $output[] = $this->format($table, $context, true);
            }

            if (!$condition->isEmpty()) {
                if (!$query) {
                    throw new QueryBuilderError("Something very bad happened.");
                }
                // @phpstan-ignore-next-line
                $query->getWhere()->raw($condition);
            }
        }

        foreach ($join as $item) {
            $output[] = $this->doFormatJoinItem($context, $item);
        }

        return \implode("\n", $output);
    }

    /**
     * Format all update from statement.
     *
     * @param Expression[] $from
     */
    protected function doFormatFrom(WriterContext $context, array $from, ?string $prefix): string
    {
        if (!$from) {
            return '';
        }

        $output = [];

        foreach ($from as $item) {
            \assert($item instanceof Expression);

            $itemOutput = $this->format($item, $context, true);

            if ($item instanceof ConstantTable) {
                if ($columnAliases = $item->getColumns()) {
                    $itemOutput .= ' (' . $this->doFormatColumnNameList($context, $columnAliases) . ')';
                }
            }

            $output[] = $itemOutput;
        }

        return ($prefix ? $prefix . ' ' : '') . \implode(', ', $output);
    }

    /**
     * When no values are set in an insert query, what should we write?
     */
    protected function doFormatInsertNoValuesStatement(WriterContext $context): string
    {
        return "DEFAULT VALUES";
    }

    /**
     * Format array of with statements.
     *
     * @param WithStatement[] $with
     */
    protected function doFormatWith(WriterContext $context, array $with): string
    {
        if (!$with) {
            return '';
        }

        $output = [];
        foreach ($with as $item) {
            \assert($item instanceof WithStatement);
            $expression = $item->getExpression();

            // @todo I don't think I can do better than that, but I'm really sorry.
            if ($expression instanceof ConstantTable && ($columnAliases = $expression->getColumns())) {
                $output[] = $this->escaper->escapeIdentifier($item->getAlias()) . ' (' . $this->doFormatColumnNameList($context, $columnAliases) . ') as (' . $this->format($expression, $context) . ')';
            } else {
                $output[] = $this->escaper->escapeIdentifier($item->getAlias()) . ' as (' . $this->format($expression, $context) . ')';
            }
        }

        return 'with ' . \implode(', ', $output);
    }

    /**
     * Format range statement.
     *
     * @param int $limit
     *   O means no limit.
     * @param int $offset
     *   0 means default offset.
     */
    protected function doFormatRange(WriterContext $context, int $limit = 0, int $offset = 0): string
    {
        if ($limit) {
            return 'limit ' . $limit . ' offset ' . $offset;
        }
        if ($offset) {
            return 'offset ' . $offset;
        }
        return '';
    }

    /**
     * Format a column name list.
     */
    protected function doFormatColumnNameList(WriterContext $context, array $columnNames): string
    {
        return \implode(
            ', ',
            \array_map(
                fn ($column) => $this->escaper->escapeIdentifier($column),
                $columnNames
            )
        );
    }

    /**
     * Format negation of another expression.
     */
    protected function formatNot(Not $expression, WriterContext $context): string
    {
        $innerExpression = $expression->getExpression();

        return 'not ' . $this->format($innerExpression, $context, true);
    }

    /**
     * Format generic comparison expression.
     */
    protected function formatComparison(Comparison $expression, WriterContext $context): string
    {
        $output = '';

        $left = $expression->getLeft();
        $right = $expression->getRight();
        $operator = $expression->getOperator();

        if ($left) {
            $output .= $this->format($left, $context, true);
        }

        if ($operator) {
            $output .= ' ' . $operator;
        }

        if ($right) {
            $output .= ' ' . $this->format($right, $context, true);
        }

        return $output;
    }

    /**
     * Format BETWEEN expression.
     */
    protected function formatBetween(Between $expression, WriterContext $context): string
    {
        $column = $expression->getColumn();
        $from = $expression->getFrom();
        $to = $expression->getTo();

        return $this->format($column, $context) . ' between ' . $this->format($from, $context) . ' and ' . $this->format($to, $context);
    }

    /**
     * Format where instance.
     */
    protected function formatWhere(Where $expression, WriterContext $context): string
    {
        if ($expression->isEmpty()) {
            // Definitely legit, except for PostgreSQL which seems to require
            // a boolean value for those expressions. In theory, booleans are
            // part of the SQL standard, but a lot of RDBMS don't support them,
            // so we keep the "1" here.
            return '1';
        }

        $output = '';
        $operator = $expression->getOperator();

        foreach ($expression->getConditions() as $expression) {
            // Do not allow an empty where to be displayed.
            if ($expression instanceof Where && $expression->isEmpty()) {
                continue;
            }

            if ($output) {
                $output .= "\n" . $operator . ' ';
            }

            if ($expression instanceof Where || $this->expressionRequiresParenthesis($expression)) {
                $output .= '(' . $this->format($expression, $context) . ')';
            } else {
                $output .= $this->format($expression, $context);
            }
        }

        return $output;
    }

    /**
     * Format a constant table expression.
     */
    protected function doFormatConstantTable(ConstantTable $expression, WriterContext $context, ?string $alias): string
    {
        $inner = null;
        foreach ($expression->getRows() as $row) {
            if ($inner) {
                $inner .= "\n," . $this->formatRow($row, $context);
            } else {
                $inner = $this->formatRow($row, $context);
            }
        }

        // Do not add column names if there are no values, otherwise there
        // probably will be a column count mismatch and it will fail.
        // This will output something such as:
        //    VALUES (1, 2), (3, 4) AS "alias" ("column1", "column2").
        // Which is the correct syntax for using a constant table in
        // a FROM clause and name its columns at the same time. This at
        // least works with PostgreSQL.
        if ($inner && $alias) {
            if ($columns = $expression->getColumns()) {
                return "(values " . $inner . ") as " . $this->escaper->escapeIdentifier($alias) . ' (' . $this->doFormatColumnNameList($context, $columns) . ')';
            }
            return "(values " . $inner . ") as " . $this->escaper->escapeIdentifier($alias);
        }

        return "values " . ($inner ?? '()');
    }

    /**
     * Format a constant table expression.
     */
    protected function formatConstantTable(ConstantTable $expression, WriterContext $context): string
    {
        return $this->doFormatConstantTable($expression, $context, null);
    }

    /**
     * Format an arbitrary row of values.
     */
    protected function formatRow(Row $expression, WriterContext $context): string
    {
        $inner = null;
        foreach ($expression->getValues() as $value) {
            $local = $this->format($value, $context, true);
            if ($inner) {
                $inner .= ', ' . $local;
            } else {
                $inner = $local;
            }
        }

        if ($expression->shouldCast()) {
            return $this->doFormatCastExpression('(' . $inner . ')', $expression->getType(), $context);
        }
        return '(' . $inner . ')';
    }

    /**
     * Format given merge query.
     */
    protected function formatMerge(Merge $query, WriterContext $context): string
    {
        $output = [];

        $table = $query->getTable();
        $columns = $query->getAllColumns();
        $escapedInsertTable = $this->escaper->escapeIdentifier($table->getName());
        $escapedUsingAlias = $this->escaper->escapeIdentifier($query->getUsingTableAlias());

        $output[] = $this->doFormatWith($context, $query->getAllWith());

        // From SQL:2003 standard, MERGE queries don't have table alias.
        $output[] = "merge into " . $escapedInsertTable;

        // USING
        $using = $query->getQuery();
        if ($using instanceof ConstantTable) {
            $output[] = 'using ' . $this->format($using, $context) . ' as ' . $escapedUsingAlias;
            if ($columnAliases = $using->getColumns()) {
                $output[] = ' (' . $this->doFormatColumnNameList($context, $columnAliases) . ')';
            }
        } else {
            $output[] = 'using (' . $this->format($using, $context) . ') as ' . $escapedUsingAlias;
        }

        // Build USING columns map.
        $usingColumnMap = [];
        foreach ($columns as $column) {
            $usingColumnMap[$column] = $escapedUsingAlias . "." . $this->escaper->escapeIdentifier($column);
        }

        // WHEN MATCHED THEN
        switch ($mode = $query->getConflictBehaviour()) {

            case Query::CONFLICT_IGNORE:
                // Do nothing.
                break;

            case Query::CONFLICT_UPDATE:
                // Exclude primary key from the UPDATE statement.
                $key = $query->getKey();
                $setColumnMap = [];
                foreach ($usingColumnMap as $column => $usingColumnExpression) {
                    if (!\in_array($column, $key)) {
                        $setColumnMap[$column] = new Raw($usingColumnExpression);
                    }
                }
                $output[] = "when matched then update set";
                $output[] = $this->doFormatUpdateSet($context, $setColumnMap);
                break;

            default:
                throw new QueryBuilderError(\sprintf("Unsupport merge conflict mode: %s", (string) $mode));
        }

        // WHEN NOT MATCHED THEN
        $output[] = 'when not matched then insert into ' . $escapedInsertTable;
        $output[] = '(' . $this->doFormatColumnNameList($context, $columns) . ')';
        $output[] = 'values (' . \implode(', ', $usingColumnMap) . ')';

        // RETURNING
        $return = $query->getAllReturn();
        if ($return) {
            $output[] = 'returning ' . $this->doFormatReturning($context, $return);
        }

        return \implode("\n", $output);
    }

    /**
     * Format given insert query.
     */
    protected function formatInsert(Insert $query, WriterContext $context): string
    {
        $output = [];

        $columns = $query->getAllColumns();

        if (!$table = $query->getTable()) {
            throw new QueryBuilderError("Insert query must target a table.");
        }

        $output[] = $this->doFormatWith($context, $query->getAllWith());
        // From SQL 92 standard, INSERT queries don't have table alias
        $output[] = 'insert into ' . $this->escaper->escapeIdentifier($table->getName());

        // Columns.
        if ($columns) {
            $output[] = '(' . $this->doFormatColumnNameList($context, $columns) . ')';
        }

        $using = $query->getQuery();
        if ($using instanceof ConstantTable) {
            if (\count($columns)) {
                $output[] = $this->format($using, $context);
            } else {
                // Assume there is no specific values, for PostgreSQL, we need to set
                // "DEFAULT VALUES" explicitely, for MySQL "() VALUES ()" will do the
                // trick
                $output[] = $this->doFormatInsertNoValuesStatement($context);
            }
        } else {
            $output[] = $this->format($using, $context);
        }

        $return = $query->getAllReturn();
        if ($return) {
            $output[] = 'returning ' . $this->doFormatReturning($context, $return);
        }

        return \implode("\n", $output);
    }

    /**
     * Format given delete query.
     */
    protected function formatDelete(Delete $query, WriterContext $context): string
    {
        $output = [];

        if (!$table = $query->getTable()) {
            throw new QueryBuilderError("Delete query must target a table.");
        }

        $output[] = $this->doFormatWith($context, $query->getAllWith());
        // This is not SQL-92 compatible, we are using USING..JOIN clause to
        // do joins in the DELETE query, which is not accepted by the standard.
        $output[] = 'delete from ' . $this->format($table, $context, true);

        $transformFirstJoinAsFrom = true;

        $from = $query->getAllFrom();
        if ($from) {
            $transformFirstJoinAsFrom = false;
            $output[] = ', ';
            $output[] = $this->doFormatFrom($context, $from, 'using');
        }

        $join = $query->getAllJoin();
        if ($join) {
            $output[] = $this->doFormatJoin($context, $join, $transformFirstJoinAsFrom, 'using', $query);
        }

        $where = $query->getWhere();
        if (!$where->isEmpty()) {
            $output[] = 'where ' . $this->format($where, $context, true);
        }

        $return = $query->getAllReturn();
        if ($return) {
            $output[] = 'returning ' . $this->doFormatReturning($context, $return);
        }

        return \implode("\n", \array_filter($output));
    }

    /**
     * Format given update query.
     */
    protected function formatUpdate(Update $query, WriterContext $context): string
    {
        $output = [];

        $columns = $query->getUpdatedColumns();
        if (empty($columns)) {
            throw new QueryBuilderError("Cannot run an update query without any columns to update.");
        }

        if (!$table = $query->getTable()) {
            throw new QueryBuilderError("Update query must have a table.");
        }

        //
        // Specific use case for DELETE, there might be JOIN, this valid for
        // all of PostgreSQL, MySQL and MSSQL.
        //
        // We have three variants to implement:
        //
        //  - PgSQL: UPDATE FROM a SET x = y FROM b, c JOIN d WHERE (SQL-92),
        //
        //  - MySQL: UPDATE FROM a, b, c, JOIN d SET x = y WHERE
        //
        //  - MSSQL: UPDATE SET x = y FROM a, b, c JOIN d WHERE
        //
        // Current implementation is SQL-92 standard (and PostgreSQL which
        // strictly respect the standard for most of its SQL syntax).
        //
        // Also note that MSSQL will allow UPDATE on a CTE query for example,
        // MySQL will allow UPDATE everywhere, in all cases that's serious
        // violations of the SQL standard and probably quite a dangerous thing
        // to use, so it's not officialy supported, even thought using some
        // expression magic you can write those queries.
        //

        $output[] = $this->doFormatWith($context, $query->getAllWith());
        $output[] = 'update ' . $this->format($table, $context);
        $output[] = 'set ' . $this->doFormatUpdateSet($context, $columns);

        $transformFirstJoinAsFrom = true;

        $from = $query->getAllFrom();
        if ($from) {
            $transformFirstJoinAsFrom = false;
            $output[] = $this->doFormatFrom($context, $from, 'from');
        }

        $join = $query->getAllJoin();
        if ($join) {
            $output[] = $this->doFormatJoin($context, $join, $transformFirstJoinAsFrom, 'from', $query);
        }

        $where = $query->getWhere();
        if (!$where->isEmpty()) {
            $output[] = 'where ' . $this->format($where, $context, true);
        }

        $return = $query->getAllReturn();
        if ($return) {
            $output[] = "returning " . $this->doFormatReturning($context, $return);
        }

        return \implode("\n", \array_filter($output));
    }

    /**
     * Format given select query.
     */
    protected function formatSelect(Select $query, WriterContext $context): string
    {
        $output = [];
        $output[] = $this->doFormatWith($context, $query->getAllWith());
        $output[] = "select";
        $output[] = $this->doFormatSelect($context, $query->getAllColumns());

        $from = $query->getAllFrom();
        if ($from) {
            $output[] = $this->doFormatFrom($context, $from, 'from');
        }

        $join = $query->getAllJoin();
        if ($join) {
            $output[] = $this->doFormatJoin($context, $join);
        }

        $where = $query->getWhere();
        if (!$where->isEmpty()) {
            $output[] = 'where ' . $this->format($where, $context);
        }

        $output[] = $this->doFormatGroupBy($context, $query->getAllGroupBy());

        $having = $query->getHaving();
        if (!$having->isEmpty()) {
            $output[] = 'having ' . $this->format($having, $context);
        }

        $output[] = $this->doFormatOrderBy($context, $query->getAllOrderBy());
        $output[] = $this->doFormatRange($context, ...$query->getRange());

        foreach ($query->getUnion() as $expression) {
            $output[] = "union " . $this->format($expression, $context);
        }

        if ($query->isForUpdate()) {
            $output[] = "for update";
        }

        return \implode("\n", \array_filter($output));
    }

    /**
     * Format raw expression.
     */
    protected function formatRaw(Raw $expression, WriterContext $context): string
    {
        return $this->parseExpression($expression, $context);
    }

    /**
     * Format raw query.
     */
    protected function formatRawQuery(RawQuery $query, WriterContext $context)
    {
        return $this->parseExpression($query, $context);
    }

    /**
     * Format value expression.
     */
    protected function formatIdentifier(Identifier $expression, WriterContext $context): string
    {
        // Allow selection such as "table".*
        $target = $expression->getName();

        if (!$expression instanceof ColumnName || '*' !== $target) {
            $target = $this->escaper->escapeIdentifier($target);
        }

        if ($namespace = $expression->getNamespace()) {
            return $this->escaper->escapeIdentifier($namespace) . '.' . $target;
        }
        return $target;
    }

    /**
     * Format value expression.
     */
    protected function formatValue(Value $expression, WriterContext $context): string
    {
        $index = $context->append($expression->getValue(), $expression->getType());

        // @todo For now this is hardcoded, but later this will be more generic
        //   fact is that for deambiguation, PostgreSQL needs arrays to be cast
        //   explicitly, otherwise it'll interpret it as a string; This might
        //   the case for some other types as well.

        return $this->escaper->writePlaceholder($index);
    }

    /**
     * Format array expression.
     */
    protected function formatArrayValue(ArrayValue $value, WriterContext $context): string
    {
        $inner = '';
        foreach ($value->getValues() as $item) {
            if ($inner) {
                $inner .= ', ';
            }
            $inner .= $this->format($item, $context, true);
        }

        $output = 'ARRAY[' . $inner .  ']';

        if ($value->shouldCast()) {
            return $this->doFormatCastExpression($output, $value->getValueType() . '[]', $context);
        }
        return $output;
    }

    /**
     * Format null expression.
     */
    protected function formatNullValue(NullValue $expression, WriterContext $context): string
    {
        return 'NULL';
    }

    /**
     * Format cast expression.
     */
    protected function doFormatCastExpression(string $expressionString, string $type, WriterContext $context): string
    {
        return 'CAST(' . $expressionString . ' AS ' . $type . ')';
    }

    /**
     * Format cast expression.
     */
    protected function formatCast(Cast $value, WriterContext $context): string
    {
        $expression = $value->getExpression();
        $expressionString = $this->format($expression, $context, true);

        // In this specific case, ROW() must contain the ROW keyword
        // otherwise it creates ambiguities.
        if ($expression instanceof Row) {
            $expressionString = 'row' . $expressionString;
        }

        return $this->doFormatCastExpression($expressionString, $value->getCastToType(), $context);
    }

    /**
     * Format like expression.
     */
    protected function formatSimilarTo(SimilarTo $expression, WriterContext $context): string
    {
        if ($expression->hasValue()) {
            $pattern = $expression->getPattern(
                $this->escaper->escapeLike(
                    $expression->getUnsafeValue(),
                    $expression->getReservedChars()
                )
            );
        } else {
            $pattern = $expression->getPattern();
        }

        $operator = $expression->isRegex() ? 'similar to' : ($expression->isCaseSensitive() ? 'like' : 'ilike');

        return $this->format($expression->getColumn(), $context) . ' ' . $operator . ' ' . $this->escaper->escapeLiteral($pattern);
    }

    /**
     * Format an expression with an alias.
     */
    protected function formatAliased(Aliased $expression, WriterContext $context): string
    {
        $alias = $expression->getAlias();
        $nestedExpression = $expression->getExpression();

        if ($alias) {
            // Exception for constant table, see doFormatConstantTable().
            if ($nestedExpression instanceof ConstantTable) {
                return $this->doFormatConstantTable($nestedExpression, $context, $alias);
            }

            return $this->format($nestedExpression, $context, true) . ' as ' . $this->escaper->escapeIdentifier($alias);
        }

        // Do not enforce parenthesis, parent will do it for us.
        return $this->format($nestedExpression, $context, false);
    }

    /**
     * Uses the connection driven escape sequences to build the parameter
     * matching regex.
     */
    private function buildParameterRegex(): void
    {
        /*
         * Escape sequence matching magical regex.
         *
         * Order is important:
         *
         *   - ESCAPE will match all driver-specific string escape sequence,
         *     therefore will prevent any other matches from happening inside,
         *
         *   - "??" will always superseed "?*",
         *
         *   - "?::WORD" will superseed "?",
         *
         *   - any "::WORD" sequence, which is a valid SQL cast, will be left
         *     as-is and required no rewrite.
         *
         * After some thoughts, this needs serious optimisation.
         *
         * I believe that a real parser would be much more efficient, if it was
         * written in any language other than PHP, but right now, preg will
         * actually be a lot faster than we will ever be.
         *
         * This regex is huge, but contain no backward lookup, does not imply
         * any recursivity, it should be fast enough.
         */
        $parameterMatch = '@
            ESCAPE
            (\?\?)|                 # Matches ??
            (\?((\:\:([\w]+))|))    # Matches ?[::WORD] (placeholders)
            @x';

        // Please see this really excellent Stack Overflow answer:
        //   https://stackoverflow.com/a/23589204
        $patterns = [];

        foreach ($this->escaper->getEscapeSequences() as $sequence) {
            $sequence = \preg_quote($sequence);
            $patterns[] = \sprintf("%s.+?%s", $sequence, $sequence);
        }

        if ($patterns) {
            $this->matchParametersRegex = \str_replace('ESCAPE', \sprintf("(%s)|", \implode("|", $patterns)), $parameterMatch);
        } else {
            // @todo Not sure about this one, added ", ''" to please phpstan.
            $this->matchParametersRegex = \str_replace('ESCAPE', '', $parameterMatch);
        }
    }
}