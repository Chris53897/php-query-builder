<?php

declare(strict_types=1);

namespace MakinaCorpus\QueryBuilder\Schema\Read;

class Column extends AbstractObject
{
    public function __construct(
        string $database,
        string $name,
        string $table,
        ?string $comment,
        string $schema,
        array $options,
        private readonly string $valueType,
        private readonly bool $nullabe = true,
        private readonly ?string $collation = null,
        private readonly ?int $length = null,
        private readonly ?int $precision = null,
        private readonly ?int $scale = null,
        private readonly bool $unsigned = false,
        private readonly ?string $default = null,
    ) {
        parent::__construct(
            comment: $comment,
            database: $database,
            name: $name,
            options: $options,
            namespace: $table,
            schema: $schema,
            type: ObjectId::TYPE_COLUMN,
        );
    }

    /**
     * Get table name.
     */
    public function getTable(): string
    {
        return $this->getNamespace();
    }

    /**
     * Get value type.
     */
    public function getValueType(): string
    {
        return $this->valueType;
    }

    /**
     * Get value type SQL expression.
     *
     * @todo This is a hack for column modification methods, and should probably
     *   live in another place, right now, it works.
     */
    public function getValueTypeSql(): string
    {
        if ($this->length) {
            return $this->valueType . '(' . $this->length . ')';
        }
        if ($this->precision) {
            if ($this->scale) {
                return $this->valueType . '(' . $this->precision . ',' . $this->scale . ')';
            }
            return $this->valueType . '(' . $this->precision . ')';
        }
        return $this->valueType;
    }

    /**
     * Is nullable.
     */
    public function isNullable(): bool
    {
        return $this->nullabe;
    }

    /**
     * Get collation.
     */
    public function getCollation(): ?string
    {
        return $this->collation;
    }

    /**
     * Get default.
     */
    public function getDefault(): ?string
    {
        return $this->default;
    }

    /**
     * Get length.
     */
    public function getLength(): ?int
    {
        return $this->length;
    }

    /**
     * Get precision.
     */
    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    /**
     * Get scale.
     */
    public function getScale(): ?int
    {
        return $this->scale;
    }

    /**
     * Is unsigned.
     *
     * Warning, unsigned integers are not part of the SQL standard, a only a few
     * vendors implement it,
     */
    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }
}
