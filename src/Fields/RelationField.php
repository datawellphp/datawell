<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Execution\Context;
use Datawell\Fields\Concerns\HasOptions;
use Datawell\Operators\Operator;
use Datawell\Options;
use Datawell\Result\EntityRef;

/**
 * A relation-backed field whose values are entity references (D21) into a target source
 * (D54): declared with references(), or inferred from the related model when exactly one
 * registered source declares it. Cardinality is introspected from the relation type when
 * the source declares a model (D20, D46). Options default to self-facet — the right
 * strategy for filtering a host dataset (D36).
 */
class RelationField extends Field
{
    use HasOptions;

    protected ?string $references = null;

    /**
     * @param  string|null  $from  the relation path, when it differs from the key
     */
    public static function make(string $key, ?string $from = null): static
    {
        return new static($key, $from);
    }

    /**
     * The source this field's values reference — its representation labels them and
     * its lookup resolves them.
     */
    public function references(string $sourceKey): static
    {
        $this->references = $sourceKey;

        return $this;
    }

    public function getReferencedSourceKey(): ?string
    {
        return $this->references;
    }

    public function type(): string
    {
        return 'relation';
    }

    /**
     * A relation field's path is a relation, never a column, however short it is.
     */
    public function isColumn(): bool
    {
        return false;
    }

    protected function singleOperators(): array
    {
        return [Operator::In, Operator::NotIn];
    }

    protected function defaultOptions(): ?Options
    {
        return Options::selfFacet();
    }

    /**
     * Resolved at describe time by the Describer, which knows the model: `source` names
     * the referenced source so consumers can resolve references generically (D54).
     *
     * @return array<string, mixed>
     */
    public function describeWith(?string $source): array
    {
        $description = $this->describe();

        if ($source !== null) {
            $description = self::insertAfter($description, 'cardinality', 'source', $source);
        }

        return $description;
    }

    public function serialize(object $row, Context $context): mixed
    {
        $value = $context->relations()->valueOf($row, $this);

        if ($value instanceof EntityRef) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return ['items' => array_map(static fn (EntityRef $ref): array => $ref->toArray(), $value['items']), 'total' => $value['total']];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private static function insertAfter(array $array, string $after, string $key, mixed $value): array
    {
        $result = [];

        foreach ($array as $k => $v) {
            $result[$k] = $v;

            if ($k === $after) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
