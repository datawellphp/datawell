<?php

declare(strict_types=1);

namespace Datawell;

use Datawell\Actions\Action;
use Datawell\Fields\Field;
use Datawell\Filters\Filter;
use Datawell\Relations\RelationIntrospector;
use Datawell\Sorts\Sort;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal The resolved shape of a source: fields with cardinality fixed, filters and
 * sorts derived from fields and merged with declared ones, actions and parameters collected.
 */
final class Definition
{
    /** @var array<string, Field> */
    private array $fields = [];

    /** @var array<string, Filter> */
    private array $filters = [];

    /** @var array<string, Sort> */
    private array $sorts = [];

    /** @var array<string, Action> */
    private array $actions = [];

    /** @var array<string, Parameter> */
    private array $parameters = [];

    /** @var list<string> */
    private array $duplicateKeys = [];

    public function __construct(
        private readonly DataSource $source,
        private readonly RelationIntrospector $introspector = new RelationIntrospector,
    ) {
        $this->collect();
    }

    public function source(): DataSource
    {
        return $this->source;
    }

    /**
     * @return class-string<Model>|null
     */
    public function model(): ?string
    {
        return $this->source->model();
    }

    /**
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $key): ?Field
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * @return array<string, Filter>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<string, Sort>
     */
    public function sorts(): array
    {
        return $this->sorts;
    }

    /**
     * @return array<string, Action>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @return array<string, Parameter>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Keys declared more than once within one collection (reported by lint).
     *
     * @return list<string>
     */
    public function duplicateKeys(): array
    {
        return $this->duplicateKeys;
    }

    private function collect(): void
    {
        $model = $this->model();

        foreach ($this->source->fields() as $field) {
            $this->put($this->fields, $field->getKey(), $field, 'field');

            if ($model !== null && ! $field->hasDeclaredCardinality()) {
                $cardinality = $this->introspector->cardinalityOf($model, $field->getPath());

                if ($cardinality !== null) {
                    $field->introspectedCardinality($cardinality);
                }
            }
        }

        foreach ($this->fields as $key => $field) {
            if ($field->isFilterable()) {
                $this->filters[$key] = Filter::make($key)->backedBy($field);
            }
        }

        foreach ($this->source->filters() as $filter) {
            $filter->backedBy($this->fields[$filter->getFieldKey()] ?? null);
            $this->filters[$filter->getKey()] = $filter;
        }

        foreach ($this->fields as $key => $field) {
            if ($field->isSortable()) {
                $this->sorts[$key] = Sort::make($key)->backedBy($field);
            }
        }

        foreach ($this->source->sorts() as $sort) {
            $sort->backedBy($this->fields[$sort->getFieldKey()] ?? null);
            $this->sorts[$sort->getKey()] = $sort;
        }

        foreach ($this->source->actions() as $action) {
            $this->put($this->actions, $action->getKey(), $action, 'action');
        }

        foreach ($this->source->parameters() as $parameter) {
            $this->put($this->parameters, $parameter->getKey(), $parameter, 'parameter');
        }
    }

    /**
     * @template T of object
     *
     * @param  array<string, T>  $collection
     * @param  T  $item
     */
    private function put(array &$collection, string $key, object $item, string $kind): void
    {
        if (isset($collection[$key])) {
            $this->duplicateKeys[] = sprintf('%s "%s"', $kind, $key);
        }

        $collection[$key] = $item;
    }
}
