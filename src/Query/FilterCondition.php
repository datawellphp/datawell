<?php

declare(strict_types=1);

namespace Datawell\Query;

use Datawell\Operators\Operator;

/**
 * A leaf of the filter tree: `{ "filter": key, "operator": op, "value": … }` (D06).
 */
final class FilterCondition
{
    public function __construct(
        public readonly string $filter,
        public readonly Operator $operator,
        public readonly mixed $value = null,
        public readonly bool $hasValue = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $leaf = ['filter' => $this->filter, 'operator' => $this->operator->value];

        if ($this->hasValue) {
            $leaf['value'] = $this->value;
        }

        return $leaf;
    }
}
