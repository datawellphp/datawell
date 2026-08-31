<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Execution\Context;
use Datawell\Operators\Operator;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class BooleanField extends Field
{
    /**
     * @param  string|null  $from  the data path, when it differs from the key
     */
    public static function make(string $key, ?string $from = null): static
    {
        return new static($key, $from);
    }

    public function type(): string
    {
        return 'boolean';
    }

    protected function singleOperators(): array
    {
        return [Operator::Is];
    }

    protected function applyColumnCondition(EloquentBuilder|QueryBuilder $query, string|Expression $column, Operator $operator, mixed $value, Context $context): void
    {
        if ($operator === Operator::Is) {
            $query->where($column, '=', (bool) $value);

            return;
        }

        parent::applyColumnCondition($query, $column, $operator, $value, $context);
    }

    public function serialize(object $row, Context $context): mixed
    {
        $value = $this->valueOf($row);

        return $value === null ? null : (bool) $value;
    }
}
