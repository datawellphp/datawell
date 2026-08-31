<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Datawell\Fields\Field;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Top-level search (D16): contains semantics, OR across searchable-and-visible fields,
 * AND across whitespace-separated terms, wildcard escaping owned here.
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
     * @param  list<Field>  $fields  searchable fields visible to the user, column-backed
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, string $search, array $fields): void
    {
        $terms = self::terms($search);

        if ($terms === [] || $fields === []) {
            return;
        }

        foreach ($terms as $term) {
            $query->where(static function (EloquentBuilder|QueryBuilder $group) use ($fields, $term): void {
                foreach ($fields as $field) {
                    $group->whereRaw(Like::clause($group, $field->getPath()), [Like::contains($term), Like::ESCAPE], 'or');
                }
            });
        }
    }
}
