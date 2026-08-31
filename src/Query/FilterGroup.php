<?php

declare(strict_types=1);

namespace Datawell\Query;

/**
 * A boolean group of leaves and groups (D07). The root is an implicit `and`;
 * nesting is capped at two levels by the validator — never flattened.
 */
final class FilterGroup
{
    /**
     * @param  'and'|'or'  $boolean
     * @param  list<FilterCondition|FilterGroup>  $conditions
     */
    public function __construct(
        public readonly string $boolean = 'and',
        public readonly array $conditions = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /**
     * The nesting depth: 1 for a group of leaves, +1 per nested group.
     */
    public function depth(): int
    {
        $deepest = 0;

        foreach ($this->conditions as $condition) {
            if ($condition instanceof self) {
                $deepest = max($deepest, $condition->depth());
            }
        }

        return 1 + $deepest;
    }

    /**
     * Every leaf, in document order.
     *
     * @return list<FilterCondition>
     */
    public function leaves(): array
    {
        $leaves = [];

        foreach ($this->conditions as $condition) {
            if ($condition instanceof self) {
                array_push($leaves, ...$condition->leaves());
            } else {
                $leaves[] = $condition;
            }
        }

        return $leaves;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'boolean' => $this->boolean,
            'conditions' => array_map(
                static fn (FilterCondition|FilterGroup $condition): array => $condition->toArray(),
                $this->conditions,
            ),
        ];
    }
}
