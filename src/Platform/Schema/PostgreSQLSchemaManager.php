<?php

declare(strict_types=1);

namespace MakinaCorpus\QueryBuilder\Platform\Schema;

use MakinaCorpus\QueryBuilder\Result\Result;
use MakinaCorpus\QueryBuilder\Result\ResultRow;
use MakinaCorpus\QueryBuilder\Schema\Column;
use MakinaCorpus\QueryBuilder\Schema\Key;
use MakinaCorpus\QueryBuilder\Schema\SchemaManager;
use MakinaCorpus\QueryBuilder\Schema\ForeignKey;

/**
 * Please note that some functions here might use information_schema tables
 * which are restricted in listings, they will show up only table information
 * the current user owns or has non-SELECT privileges onto.
 */
class PostgreSQLSchemaManager extends SchemaManager
{
    #[\Override]
    public function supportsTransaction(): bool
    {
        return true;
    }

    #[\Override]
    public function listDatabases(): array
    {
        return $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT datname
                FROM pg_database
                ORDER BY datname ASC
                SQL
            )
            ->fetchFirstColumn()
        ;
    }

    #[\Override]
    public function listSchemas(string $database): array
    {
        return $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT
                    schema_name
                FROM information_schema.schemata
                WHERE
                    catalog_name = ?
                    AND schema_name NOT LIKE 'pg\_%'
                    AND schema_name != 'information_schema'
                ORDER BY schema_name ASC
                SQL,
                [$database]
            )
            ->fetchFirstColumn()
        ;
    }

    #[\Override]
    public function listTables(string $database, string $schema = 'public'): array
    {
        return $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT
                    quote_ident(table_name) AS table_name
                FROM information_schema.tables
                WHERE
                    table_catalog = ?
                    AND table_schema = ?
                    AND table_name <> 'geometry_columns'
                    AND table_name <> 'spatial_ref_sys'
                    AND table_type <> 'VIEW'
                ORDER BY table_name ASC
                SQL,
                [$database, $schema]
            )
            ->fetchFirstColumn()
        ;
    }

    #[\Override]
    public function tableExists(string $database, string $name, string $schema = 'public'): bool
    {
        return (bool) $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT
                    true
                FROM information_schema.tables
                WHERE
                    table_catalog = ?
                    AND table_schema = ?
                    AND table_name = ?
                    AND table_type <> 'VIEW'
                SQL,
                [$database, $schema, $name]
            )
            ->fetchOne()
        ;
    }

    #[\Override]
    protected function getTableComment(string $database, string $name, string $schema = 'public'): ?string
    {
        return $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT
                    description
                FROM pg_description
                WHERE objoid = (
                    SELECT oid FROM pg_class
                    WHERE
                        relnamespace = to_regnamespace(?)
                        AND oid = to_regclass(?)
                )
                SQL,
                [$schema, $name]
            )
            ->fetchOne()
        ;
    }

    #[\Override]
    protected function getTableColumns(string $database, string $name, string $schema = 'public'): array
    {
        $defaultCollation = $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT datcollate FROM pg_database WHERE datname = ?
                SQL,
                [$database]
            )
            ->fetchOne()
        ;

        return $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT
                    column_name,
                    data_type,
                    udt_name,
                    is_nullable,
                    character_maximum_length,
                    numeric_precision,
                    numeric_scale,
                    collation_name,
                    column_default
                    -- comment
                FROM information_schema.columns
                WHERE
                    table_catalog = ?
                    AND table_schema = ?
                    AND table_name = ?
                ORDER BY ordinal_position ASC
                SQL,
                [$database, $schema, $name]
            )
            ->setHydrator(fn (ResultRow $row) => new Column(
                collation: $row->get('collation_name', 'string') ?? $defaultCollation,
                comment: null, // @todo,
                database: $database,
                length: $row->get('character_maximum_length', 'int'),
                name: $row->get('column_name', 'string'),
                nullabe: $row->get('is_nullable', 'string') !== 'NO',
                options: [],
                precision: $row->get('numeric_precision', 'int'),
                scale: $row->get('numeric_scale', 'int'),
                schema: $schema,
                table: $name,
                unsigned: false,
                valueType: $row->get('udt_name', 'string'),
            ))
            ->fetchAllHydrated()
        ;
    }

    #[\Override]
    protected function getTablePrimaryKey(string $database, string $name, string $schema = 'public'): ?Key
    {
        $result = $this->getAllTableKeysInfo($database, $name, $schema);

        while ($row = $result->fetchRow()) {
            if ($row->get('type') === 'p') {
                return new Key(
                    columnNames: $row->get('column_source', 'string[]'),
                    comment: null, // @todo
                    database: $database,
                    name: $row->get('name', 'string'),
                    options: [],
                    schema: $row->get('table_source_schema', 'string'),
                    table: $row->get('table_source', 'string'),
                );
            }
        }

        return null;
    }

    #[\Override]
    protected function getTableForeignKeys(string $database, string $name, string $schema = 'public'): array
    {
        $ret = [];
        $result = $this->getAllTableKeysInfo($database, $name, $schema);

        while ($row = $result->fetchRow()) {
            if ($row->get('type') === 'f' && $row->get('table_source', 'string') === $name) {
                $ret[] = new ForeignKey(
                    columnNames: $row->get('column_source', 'string[]'),
                    comment: null, // @todo
                    database: $database,
                    foreignColumnNames: $row->get('column_target', 'string[]'),
                    foreignSchema: $row->get('table_target_schema', 'string'),
                    foreignTable: $row->get('table_target', 'string'),
                    name: $row->get('name', 'string'),
                    options: [],
                    schema: $row->get('table_source_schema', 'string'),
                    table: $row->get('table_source', 'string'),
                );
            }
        }

        return $ret;
    }

    #[\Override]
    protected function getTableReverseForeignKeys(string $database, string $name, string $schema = 'public'): array
    {
        $ret = [];
        $result = $this->getAllTableKeysInfo($database, $name, $schema);

        while ($row = $result->fetchRow()) {
            if ($row->get('type') === 'f' && $row->get('table_source', 'string') !== $name) {
                $ret[] = new ForeignKey(
                    columnNames: $row->get('column_source', 'string[]'),
                    comment: null, // @todo
                    database: $database,
                    foreignColumnNames: $row->get('column_target', 'string[]'),
                    foreignSchema: $row->get('table_target_schema', 'string'),
                    foreignTable: $row->get('table_target', 'string'),
                    name: $row->get('name', 'string'),
                    options: [],
                    schema: $row->get('table_source_schema', 'string'),
                    table: $row->get('table_source', 'string'),
                );
            }
        }

        return $ret;
    }

    /**
     * Get all table keys info.
     *
     * This SQL statement is terrible to maintain, so for maintainability,
     * we prefer to load all even when uncessary and filter out the result.
     *
     * Since this is querying the catalog, it will be fast no matter how
     * much result this yields.
     */
    private function getAllTableKeysInfo(string $database, string $name, string $schema = 'public'): Result
    {
        return $this
            ->queryExecutor
            ->executeQuery(
                <<<SQL
                SELECT
                    con.conname AS name,
                    class_src.relname AS table_source,
                    (
                        SELECT nspname
                        FROM pg_catalog.pg_namespace
                        WHERE
                            oid = class_src.relnamespace
                    ) AS table_source_schema,
                    class_tgt.relname AS table_target,
                    (
                        SELECT nspname
                        FROM pg_catalog.pg_namespace
                        WHERE
                            oid = class_tgt.relnamespace
                    ) AS table_target_schema,
                    (
                        SELECT array_agg(attname)
                        FROM pg_catalog.pg_attribute
                        WHERE
                            attrelid = con.conrelid
                            AND attnum IN (SELECT * FROM unnest(con.conkey))
                    ) AS column_source,
                    (
                        SELECT array_agg(attname)
                        FROM pg_catalog.pg_attribute
                        WHERE
                            attrelid = con.confrelid
                            AND attnum IN (SELECT * FROM unnest(con.confkey))
                    ) AS column_target,
                    con.contype AS type
                FROM pg_catalog.pg_constraint con
                JOIN pg_catalog.pg_class class_src
                    ON class_src.oid = con.conrelid
                LEFT JOIN pg_catalog.pg_class class_tgt
                    ON class_tgt.oid = con.confrelid
                WHERE
                    con.contype IN ('f', 'p')
                    AND con.connamespace =  to_regnamespace(?)
                    AND (
                        con.conrelid =  to_regclass(?)
                        OR con.confrelid =  to_regclass(?)
                    )
                SQL,
                [$schema, $name, $name]
            )
        ;
    }
}
