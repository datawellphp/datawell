<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Fields\Concerns\HasGrains;
use Datawell\Operators\Operator;

/**
 * An instant stored in UTC, compared and bucketed in the effective timezone (D14).
 */
class DateTimeField extends Field
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
        return 'dateTime';
    }

    protected function singleOperators(): array
    {
        return [Operator::On, Operator::Before, Operator::After, Operator::Between];
    }
}
