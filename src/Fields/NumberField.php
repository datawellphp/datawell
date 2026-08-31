<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Execution\Context;
use Datawell\Operators\Operator;

class NumberField extends Field
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
        return 'number';
    }

    protected function singleOperators(): array
    {
        return [Operator::Equals, Operator::NotEquals, Operator::Gt, Operator::Gte, Operator::Lt, Operator::Lte, Operator::Between];
    }

    public function castValue(mixed $value): mixed
    {
        return is_numeric($value) ? $value + 0 : $value;
    }

    public function serialize(object $row, Context $context): mixed
    {
        $value = $this->valueOf($row);

        return is_numeric($value) ? $value + 0 : $value;
    }
}
