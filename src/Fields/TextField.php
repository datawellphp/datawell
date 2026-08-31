<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Operators\Operator;

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
}
