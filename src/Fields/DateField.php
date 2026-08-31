<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Fields\Concerns\HasGrains;
use Datawell\Operators\Operator;
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

        throw new UnsupportedException(sprintf('Date compilation for field "%s" lands with the timezone slice.', $this->getKey()));
    }
}
