<?php

declare(strict_types=1);

namespace Datawell\Fields;

use Datawell\Concerns\HasDescription;
use Datawell\Concerns\HasKey;
use Datawell\Concerns\HasLabel;
use Datawell\Concerns\HasVisibility;
use Datawell\Enums\AggregateType;
use Datawell\Enums\Cardinality;
use Datawell\Operators\Operator;

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
}
