<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Concerns\HasDescription;
use Datawell\Concerns\HasKey;
use Datawell\Concerns\HasLabel;
use Datawell\Concerns\HasVisibility;
use Datawell\Enums\AggregateType;
use Datawell\Enums\Cardinality;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Operators\Operator;
use Datawell\Relations\Aggregation;
use Datawell\Relations\RelationResolver;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A typed unit of data exposed by a source (D03, D33). One concrete class per type,
 * one level deep, `::make()` only; cardinality is an orthogonal property (D20).
 */
abstract class Field
{
    use HasDescription;
    use HasKey;
    use HasLabel;
    use HasVisibility;

    /** The data path (dot notation for relations); the published key stays flat. */
    protected string $from;

    /** Explicitly declared cardinality; null means "auto" (introspected, else single). */
    protected ?Cardinality $cardinality = null;

    /** Cardinality fixed by introspection at definition time (D46). */
    protected ?Cardinality $introspected = null;

    protected bool $sortable = false;

    protected bool $filterable = false;

    protected bool $groupable = false;

    protected bool $searchable = false;

    protected bool $nullable = false;

    /** @var list<AggregateType> */
    protected array $aggregates = [];

    /** Declared when this field is an aggregate over a relation (D55). */
    protected ?Aggregation $aggregation = null;

    /**
     * Concrete types expose `::make()` — the one authoring style (D33) — each with
     * the signature its type needs; the constructor itself is not the authoring API.
     *
     * @param  string|null  $from  the data path, when it differs from the key (`signer.email`)
     */
    final public function __construct(string $key, ?string $from = null)
    {
        $this->key = $key;
        $this->from = $from ?? $key;
    }

    /**
     * The wire type string (`text`, `number`, …). Built-ins map to the D09 tables;
     * custom types publish their own.
     */
    abstract public function type(): string;

    /**
     * The canonical operator set for a single value of this type (D09).
     *
     * @return list<Operator>
     */
    abstract protected function singleOperators(): array;

    public function getPath(): string
    {
        return $this->from;
    }

    public function cardinality(Cardinality $cardinality): static
    {
        $this->cardinality = $cardinality;

        return $this;
    }

    /**
     * @internal Set by the definition once relation paths have been introspected.
     */
    public function introspectedCardinality(Cardinality $cardinality): static
    {
        $this->introspected = $cardinality;

        return $this;
    }

    public function hasDeclaredCardinality(): bool
    {
        return $this->cardinality !== null;
    }

    public function getCardinality(): Cardinality
    {
        return $this->cardinality ?? $this->introspected ?? Cardinality::Single;
    }

    public function isMany(): bool
    {
        return $this->getCardinality() === Cardinality::Many;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function filterable(bool $filterable = true): static
    {
        $this->filterable = $filterable;

        return $this;
    }

    public function groupable(bool $groupable = true): static
    {
        $this->groupable = $groupable;

        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Permitted aggregates when this field is used as a measure.
     */
    public function aggregates(AggregateType ...$aggregates): static
    {
        $this->aggregates = array_values($aggregates);

        return $this;
    }

    /**
     * Make this field the count of a to-many relation (D55): `reminders_count` as a
     * Number that sorts, filters and charts like any other.
     */
    public function countOf(string $relation): static
    {
        $this->aggregation = new Aggregation(AggregateType::Count, $relation);

        return $this;
    }

    public function sumOf(string $relation, string $column): static
    {
        return $this->aggregateOf(AggregateType::Sum, $relation, $column);
    }

    public function avgOf(string $relation, string $column): static
    {
        return $this->aggregateOf(AggregateType::Avg, $relation, $column);
    }

    public function minOf(string $relation, string $column): static
    {
        return $this->aggregateOf(AggregateType::Min, $relation, $column);
    }

    public function maxOf(string $relation, string $column): static
    {
        return $this->aggregateOf(AggregateType::Max, $relation, $column);
    }

    /**
     * Sum, avg, min and max of nothing are NULL: these fields are nullable by nature (D55).
     */
    protected function aggregateOf(AggregateType $fn, string $relation, string $column): static
    {
        $this->aggregation = new Aggregation($fn, $relation, $column);
        $this->nullable = true;

        return $this;
    }

    public function getAggregation(): ?Aggregation
    {
        return $this->aggregation;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function isGroupable(): bool
    {
        return $this->groupable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * @return list<AggregateType>
     */
    public function getAggregates(): array
    {
        return $this->aggregates;
    }

    /**
     * The full legal operator set for this field: type × cardinality × nullability (D09, D11, D49).
     *
     * @return list<Operator>
     */
    public function operators(): array
    {
        if ($this->isMany()) {
            return Operator::manyOperators();
        }

        return $this->nullable
            ? [...$this->singleOperators(), ...Operator::nullOperators()]
            : $this->singleOperators();
    }

    /**
     * The schema fragment for this field. Only true capabilities are emitted.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $description = [
            'key' => $this->key,
            'label' => $this->getLabel(),
            'type' => $this->type(),
            'cardinality' => $this->getCardinality()->value,
        ];

        foreach (['sortable', 'filterable', 'groupable', 'searchable', 'nullable'] as $flag) {
            if ($this->{$flag}) {
                $description[$flag] = true;
            }
        }

        if ($this->aggregates !== []) {
            $description['aggregates'] = array_map(
                static fn (AggregateType $aggregate): string => $aggregate->value,
                $this->aggregates,
            );
        }

        $description += $this->describeExtra();

        if ($this->description !== null) {
            $description['description'] = $this->description;
        }

        return $description;
    }

    /**
     * Type-specific additions to the schema fragment (grains, options, …).
     *
     * @return array<string, mixed>
     */
    protected function describeExtra(): array
    {
        return [];
    }

    /**
     * Whether this field's data path names a plain column on the base table.
     */
    public function isColumn(): bool
    {
        return ! str_contains($this->from, '.');
    }

    /**
     * Compile one validated filter condition onto the query. The operator is already
     * known to be legal for this field and the value already fits its shape (D09).
     * A plain column compiles in place; a path through a relation compiles as a
     * semi-join (`whereHas`) so to-many paths never duplicate rows (design doc §6).
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    public function applyCondition(EloquentBuilder|QueryBuilder $query, Operator $operator, mixed $value, Context $context): void
    {
        $relations = $context->relations();

        if ($this->aggregation !== null) {
            [$expression, $bindings] = $relations->aggregate($query, $this->aggregation);
            $query->addBinding($bindings, 'where');
            $this->applyColumnCondition($query, $expression, $operator, $value, $context);

            return;
        }

        $resolved = $relations->resolveField($query, $this);
        $path = $resolved->path;

        if (! $path->crossesRelation()) {
            $this->applyColumnCondition($query, RelationResolver::qualify($query, $path->column ?? $this->from), $operator, $value, $context);

            return;
        }

        $column = (string) $path->column;
        $on = function (EloquentBuilder $related, Operator $op, mixed $v) use ($column, $context): void {
            $this->applyColumnCondition($related, $related->qualifyColumn($column), $op, $v, $context);
        };

        match ($operator) {
            Operator::IsEmpty => $query->where(static function (EloquentBuilder|QueryBuilder $group) use ($relations, $path, $on): void {
                $relations->has($group, $path, null, exists: false);
                $relations->has($group, $path, static fn (EloquentBuilder $related) => $on($related, Operator::IsEmpty, null), boolean: 'or');
            }),
            Operator::IsNotEmpty => $relations->has($query, $path, static fn (EloquentBuilder $related) => $on($related, Operator::IsNotEmpty, null)),
            Operator::HasAny => $relations->has($query, $path, static fn (EloquentBuilder $related) => $on($related, Operator::In, $value)),
            Operator::HasNone => $relations->has($query, $path, static fn (EloquentBuilder $related) => $on($related, Operator::In, $value), exists: false),
            Operator::HasAll => array_walk($value, static fn (mixed $one) => $relations->has($query, $path, static fn (EloquentBuilder $related) => $on($related, Operator::Equals, $one))),
            default => $relations->has($query, $path, static fn (EloquentBuilder $related) => $on($related, $operator, $value)),
        };
    }

    /**
     * The per-type compilation of an operator against one column — the hook concrete
     * types override; the column is already qualified and resolved (or is an aggregate
     * field's subselect expression, whose bindings are already on the query).
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    protected function applyColumnCondition(EloquentBuilder|QueryBuilder $query, string|Expression $column, Operator $operator, mixed $value, Context $context): void
    {
        match ($operator) {
            Operator::IsEmpty => $query->whereNull($column),
            Operator::IsNotEmpty => $query->whereNotNull($column),
            Operator::Equals => $query->where($column, '=', $this->castValue($value)),
            Operator::NotEquals => $query->where($column, '!=', $this->castValue($value)),
            Operator::Gt => $query->where($column, '>', $this->castValue($value)),
            Operator::Gte => $query->where($column, '>=', $this->castValue($value)),
            Operator::Lt => $query->where($column, '<', $this->castValue($value)),
            Operator::Lte => $query->where($column, '<=', $this->castValue($value)),
            Operator::Between => $query->whereBetween($column, [$this->castValue($value['from']), $this->castValue($value['to'])]),
            Operator::In => $query->whereIn($column, array_map($this->castValue(...), $value)),
            Operator::NotIn => $query->whereNotIn($column, array_map($this->castValue(...), $value)),
            default => throw new UnsupportedException(sprintf('Operator "%s" has no compilation for field "%s".', $operator->value, $this->key)),
        };
    }

    /**
     * Normalise a wire value to what the database compares against.
     */
    public function castValue(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Read this field's raw value off a fetched row.
     */
    public function valueOf(object $row): mixed
    {
        return data_get($row, $this->from);
    }

    /**
     * The wire form of this field's value for one row.
     */
    public function serialize(object $row, Context $context): mixed
    {
        return $this->valueOf($row);
    }
}
