<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Fields\Concerns\HasOptions;
use Datawell\Operators\Operator;
use Datawell\Options;

/**
 * A relation-backed field whose values are entity references (D21). Cardinality is
 * introspected from the relation type when the source declares a model (D20, D46).
 * Options default to self-facet — the right strategy for filtering a host dataset (D36).
 */
class RelationField extends Field
{
    use HasOptions;

    /**
     * @param  string|null  $from  the data path, when it differs from the key
     */
    public static function make(string $key, ?string $from = null): static
    {
        return new static($key, $from);
    }

    public function type(): string
    {
        return 'relation';
    }

    protected function singleOperators(): array
    {
        return [Operator::In, Operator::NotIn];
    }

    protected function defaultOptions(): ?Options
    {
        return Options::selfFacet();
    }
}
