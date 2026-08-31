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
 * @internal Walks a dot path on a model and classifies its cardinality (D20).
 * The seam that becomes Phase 3's RelationResolver::cardinalityOf().
 */
class RelationIntrospector
{
    /**
     * @param  class-string<Model>  $model
     * @return Cardinality|null null when the path crosses no relation (a plain column)
     */
    public function cardinalityOf(string $model, string $path): ?Cardinality
    {
        $instance = new $model;
        $many = false;
        $crossed = false;

        foreach (explode('.', $path) as $segment) {
            if (! method_exists($instance, $segment)) {
                break;
            }

            $relation = $instance->{$segment}();

            if (! $relation instanceof Relation) {
                break;
            }

            $crossed = true;
            $many = $many || $this->isMany($relation);
            $instance = $relation->getRelated();
        }

        if (! $crossed) {
            return null;
        }

        return $many ? Cardinality::Many : Cardinality::Single;
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
