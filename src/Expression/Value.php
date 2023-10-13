<?php

declare(strict_types=1);

namespace MakinaCorpus\QueryBuilder\Expression;

use MakinaCorpus\QueryBuilder\Expression;

/**
 * Represents a raw value, along with an optional type.
 *
 * Value type may be used for later value conversion and will be propagated
 * to ArgumentBag instance, but will have no impact on formatted SQL string.
 *
 * Value itself can be anything including an Expression instance.
 */
class Value implements Expression
{
    public function __construct(
        private mixed $value,
        private ?string $type = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function returns(): bool
    {
        return true;
    }

    /**
     * Get value.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Get value type.
     */
    public function getType(): ?string
    {
        return $this->type;
    }
}