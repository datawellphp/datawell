<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Compilation\Like;
use Datawell\Compilation\Raw;
use Datawell\Execution\Context;
use Datawell\Operators\Operator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class TextField extends Field
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
        return 'text';
    }

    protected function singleOperators(): array
    {
        return [Operator::Equals, Operator::NotEquals, Operator::Contains, Operator::StartsWith, Operator::EndsWith];
    }

    protected function applyColumnCondition(EloquentBuilder|QueryBuilder $query, string $column, Operator $operator, mixed $value, Context $context): void
    {
        $pattern = match ($operator) {
            Operator::Contains => Like::contains((string) $value),
            Operator::StartsWith => Like::startsWith((string) $value),
            Operator::EndsWith => Like::endsWith((string) $value),
            default => null,
        };

        if ($pattern === null) {
            parent::applyColumnCondition($query, $column, $operator, $value, $context);

            return;
        }

        $query->whereRaw(Raw::like($query, $column), [$pattern, Raw::ESCAPE]);
    }

    public function castValue(mixed $value): mixed
    {
        return is_scalar($value) ? (string) $value : $value;
    }
}
