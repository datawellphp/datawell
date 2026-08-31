<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Compilation\Dates;
use Datawell\Execution\Context;
use Datawell\Fields\Concerns\HasGrains;
use Datawell\Operators\Operator;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A wall date: never timezone-converted (D14).
 */
class DateField extends Field
{
    use HasGrains;

    /**
     * @param  string|null  $from  the data path, when it differs from the key
     */
    public static function make(string $key, ?string $from = null): static
    {
        return new static($key, $from);
    }

    public function type(): string
    {
        return 'date';
    }

    protected function singleOperators(): array
    {
        return [Operator::On, Operator::Before, Operator::After, Operator::Between];
    }

    public function applyCondition(EloquentBuilder|QueryBuilder $query, Operator $operator, mixed $value, Context $context): void
    {
        if (in_array($operator, [Operator::IsEmpty, Operator::IsNotEmpty], true)) {
            parent::applyCondition($query, $operator, $value, $context);

            return;
        }

        foreach (Dates::comparisons($operator, $value, $context, instant: false) as [$comparison, $boundary]) {
            $query->where($this->getPath(), $comparison, $boundary);
        }
    }

    public function serialize(object $row, Context $context): mixed
    {
        $value = $this->valueOf($row);

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
