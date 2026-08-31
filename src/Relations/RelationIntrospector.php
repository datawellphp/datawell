<?php

declare(strict_types=1);

namespace Datawell\Relations;

use Datawell\Enums\Cardinality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @internal Walks a dot path on a model, splitting it into relations and column (D20).
 * Definition-time (cardinality) and run-time (the strategies) both resolve paths here,
 * so a segment is a relation in exactly one place: when the model has a method of that
 * name returning a Relation.
 */
class RelationIntrospector
{
    /**
     * @param  class-string<Model>  $model
     */
    public function resolve(string $model, string $path): Resolved
    {
        $instance = new $model;
        $segments = explode('.', $path);
        $relations = [];
        $many = false;
        $column = null;

        foreach ($segments as $index => $segment) {
            $relation = $this->relationOn($instance, $segment);

            if ($relation === null) {
                $column = implode('.', array_slice($segments, $index));

                break;
            }

            $relations[] = $segment;
            $many = $many || $this->isMany($relation);
            $instance = $relation->getRelated();
        }

        return new Resolved(
            Path::make($relations, $column),
            $relations === [] ? null : ($many ? Cardinality::Many : Cardinality::Single),
            $relations === [] ? null : $instance::class,
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @return Cardinality|null null when the path crosses no relation (a plain column)
     */
    public function cardinalityOf(string $model, string $path): ?Cardinality
    {
        return $this->resolve($model, $path)->cardinality;
    }

    /**
     * @return Relation<Model, Model, mixed>|null
     */
    protected function relationOn(Model $instance, string $segment): ?Relation
    {
        if (! method_exists($instance, $segment)) {
            return null;
        }

        $relation = $instance->{$segment}();

        return $relation instanceof Relation ? $relation : null;
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    protected function isMany(Relation $relation): bool
    {
        return $relation instanceof HasMany
            || $relation instanceof BelongsToMany
            || $relation instanceof MorphMany
            || $relation instanceof HasManyThrough;
    }
}
