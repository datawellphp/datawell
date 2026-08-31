<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Fields\Concerns\HasGrains;
use Datawell\Operators\Operator;

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
}
