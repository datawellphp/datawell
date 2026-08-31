<?php

declare(strict_types=1);

namespace Datawell;

use Datawell\Actions\Action;
use Datawell\Attributes\Model as ModelAttribute;
use Datawell\Fields\Field;
use Datawell\Filters\Filter;
use Datawell\Schema\Describer;
use Datawell\Schema\Schema;
use Datawell\Sorts\Sort;
use Datawell\Support\Key;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionClass;

/**
 * The central unit: one declaration of what a unit of data is, what shape it has,
 * how it can be sliced, what can be done to it, and who may see it.
 */
abstract class DataSource
{
    private ?Definition $definition = null;

    /**
     * The stable wire identity (D02, D30). Stamped literally by `make:datawell`; never derived.
     */
    abstract public function key(): string;

    /**
     * Presentation name; defaults from the key and is safe to derive forever (D30).
     */
    public function name(): string
    {
        return Key::label($this->key());
    }

    /**
     * AI-facing prose (D30). No default — the lint warns when it is missing.
     */
    public function description(): string
    {
        return '';
    }

    /**
     * May this user know the source exists? Failing means absent, not forbidden (D18, D31).
     */
    public function visible(Authenticatable $user): bool
    {
        return true;
    }

    /**
     * May this user run the source with these validated parameters? Runs after rules and
     * provenance; failures are masked as invalid parameters (D31).
     */
    public function authorize(Authenticatable $user, Params $params): bool
    {
        return true;
    }

    /**
     * The entity's calling card, inherited by every reference to this source (D21, D34).
     */
    abstract public function representation(): Representation;

    /**
     * @return list<Parameter>
     */
    public function parameters(): array
    {
        return [];
    }

    /**
     * The scoped base query. Row security lives here; everything downstream inherits it.
     *
     * @return EloquentBuilder<covariant Model>|QueryBuilder
     */
    abstract public function query(Params $params): EloquentBuilder|QueryBuilder;

    /**
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * Additional or narrowed filters; field-backed filters derive automatically.
     *
     * @return list<Filter>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Additional custom sorts; sortable fields derive automatically.
     *
     * @return list<Sort>
     */
    public function sorts(): array
    {
        return [];
    }

    /**
     * The resting sort order, by sort key: `['requested_at' => 'desc']` (D47).
     *
     * @return array<string, string>
     */
    public function defaultSort(): array
    {
        return [];
    }

    /**
     * @return list<Action>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Aggregated Laravel validation rules for the parameters, keyed by parameter (D04).
     *
     * @return array<string, list<string|object>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->parameters() as $parameter) {
            $rules[$parameter->getKey()] = $parameter->getRules();
        }

        return $rules;
    }

    /**
     * The backing Eloquent model, read from the #[Model] attribute (D46); null for model-less sources.
     *
     * @return class-string<Model>|null
     */
    public function model(): ?string
    {
        $attributes = (new ReflectionClass($this))->getAttributes(ModelAttribute::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->class;
    }

    /**
     * @internal The resolved definition, built once per instance.
     */
    public function definition(): Definition
    {
        return $this->definition ??= new Definition($this);
    }

    /**
     * The contract as this user sees it (D18): hidden things are absent.
     */
    public function describe(Authenticatable $user): Schema
    {
        return Container::getInstance()->make(Describer::class)->describe($this, $user);
    }
}
