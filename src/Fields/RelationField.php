<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Closure;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Fields\Concerns\HasOptions;
use Datawell\Operators\Operator;
use Datawell\Options;
use Datawell\Result\EntityRef;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /**
     * Values are ids into the target: `in`/`hasAny` are semi-joins on the related key,
     * `notIn`/`hasNone` anti-joins (a row with no related entity matches, D54), `hasAll`
     * one semi-join per id, the null operators plain existence.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public function applyCondition(EloquentBuilder|QueryBuilder $query, Operator $operator, mixed $value, Context $context): void
    {
        $relations = $context->relations();
        $path = $relations->resolveField($query, $this)->path;

        if (! $path->endsOnRelation()) {
            throw new UnsupportedException(sprintf('Relation field "%s" must name a relation, not the column "%s".', $this->getKey(), (string) $path->column));
        }

        $keyed = static fn (mixed $ids): Closure => static fn (EloquentBuilder $related): mixed => $related->whereIn($related->qualifyColumn($related->getModel()->getKeyName()), is_array($ids) ? $ids : [$ids]);

        match ($operator) {
            Operator::In, Operator::HasAny => $relations->has($query, $path, $keyed($value)),
            Operator::NotIn, Operator::HasNone => $relations->has($query, $path, $keyed($value), exists: false),
            Operator::HasAll => array_walk($value, static fn (mixed $id) => $relations->has($query, $path, $keyed($id))),
            Operator::IsNotEmpty => $relations->has($query, $path, null),
            Operator::IsEmpty => $relations->has($query, $path, null, exists: false),
            default => throw new UnsupportedException(sprintf('Operator "%s" has no compilation for relation field "%s".', $operator->value, $this->getKey())),
        };
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
