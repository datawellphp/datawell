<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Datawell\Execution\Context;
use Datawell\Fields\Field;
use Datawell\Fields\RelationField;
use Datawell\Relations\Path;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Top-level search (D16): contains semantics, OR across searchable-and-visible fields,
 * AND across whitespace-separated terms, wildcard escaping owned here. A relation field
 * searches its target's display label; a relation-backed text field its column — both
 * through `whereHas`, so a to-many match never duplicates rows (design doc §6).
 */
final class Search
{
    /**
     * @return list<string>
     */
    public static function terms(string $search): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($search)) ?: [], static fn (string $term): bool => $term !== ''));
    }

    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  list<Field>  $fields  searchable fields visible to the user
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, string $search, array $fields, Context $context): void
    {
        $terms = self::terms($search);

        if ($terms === [] || $fields === []) {
            return;
        }

        $targets = array_map(static fn (Field $field): Path => self::pathOf($query, $field, $context), $fields);

        foreach ($terms as $term) {
            $query->where(static function (EloquentBuilder|QueryBuilder $group) use ($targets, $term, $context): void {
                foreach ($targets as $path) {
                    $column = (string) $path->column;

                    if (! $path->crossesRelation()) {
                        $group->whereRaw(Raw::like($group, $column), [Like::contains($term), Raw::ESCAPE], 'or');

                        continue;
                    }

                    $context->relations()->has($group, $path, static function (EloquentBuilder $related) use ($column, $term): void {
                        $related->whereRaw(Raw::like($related, $related->qualifyColumn($column)), [Like::contains($term), Raw::ESCAPE]);
                    }, boolean: 'or');
                }
            });
        }
    }

    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    private static function pathOf(EloquentBuilder|QueryBuilder $query, Field $field, Context $context): Path
    {
        $relations = $context->relations();

        if ($field instanceof RelationField && $query instanceof EloquentBuilder) {
            return $relations->labelPath($field, $query->getModel()::class);
        }

        return $relations->resolveField($query, $field)->path;
    }
}
