<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Carbon\CarbonImmutable;
use Datawell\Definition;
use Datawell\Enums\AggregateType;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Fields\Field;
use Datawell\Fields\RelationField;
use Datawell\Query\AggregateSpec;
use Datawell\Query\GroupSpec;
use Datawell\Relations\RelationResolver;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The reports surface (design doc §3.6, D15, D25): group keys become select expressions
 * and GROUP BY terms, aggregates become measures; result rows are turned into buckets
 * whose group values are entity references and whose date grains are period starts.
 */
class Grouping
{
    /**
     * The wire key an aggregate lands under: `count`, or `<fn>_<field>`.
     */
    public static function aggregateKey(AggregateSpec $aggregate): string
    {
        return $aggregate->field === null ? $aggregate->fn : $aggregate->fn.'_'.$aggregate->field;
    }

    /**
     * Whether a group would bucket an instant by grain on a driver that cannot convert
     * timezones (D51). Returns the offending group index, or null.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  list<GroupSpec>  $groups
     */
    public static function unsupportedGrain(EloquentBuilder|QueryBuilder $query, array $groups, Definition $definition, Context $context): ?int
    {
        if ($context->timezone === 'UTC' || Raw::driver($query) !== 'sqlite') {
            return null;
        }

        foreach ($groups as $index => $group) {
            $field = $definition->field($group->key);

            if ($group->grain !== null && $field !== null && $field->type() === 'dateTime') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Compile the grouped select; returns, per group, the column its null placement
     * orders by (null for grains, which are never null-placed).
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  list<GroupSpec>  $groups
     * @param  list<AggregateSpec>  $aggregates
     * @return list<string|null>
     */
    public function compile(EloquentBuilder|QueryBuilder $query, array $groups, array $aggregates, Definition $definition, Context $context): array
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $selects = [];
        $bindings = [];
        $columns = [];

        foreach ($groups as $index => $group) {
            $field = $definition->field($group->key) ?? throw new UnsupportedException(sprintf('Group "%s" reached the compiler unvalidated.', $group->key));
            $alias = 'g'.$index;

            if ($field instanceof RelationField) {
                // A to-one relation dimension joins and groups by the target's key and
                // label together, so the bucket is a reference (§6; many is lint-rejected).
                [$idColumn, $labelColumn] = $this->relationColumns($query, $field, $context);
                $selects[] = $idColumn.' as '.$alias;
                $selects[] = $labelColumn.' as '.$alias.'_label';
                $query->groupBy($idColumn, $labelColumn);
                $columns[$index] = $idColumn;

                continue;
            }

            $column = $this->groupColumn($query, $field, $context);

            if ($group->grain === null) {
                $selects[] = $column.' as '.$alias;
                $query->groupBy($column);
                $columns[$index] = $column;

                continue;
            }

            $columns[$index] = null;

            [$expression, $expressionBindings] = Raw::grain(
                $query,
                $column,
                $group->grain,
                $field->type() === 'dateTime' && $context->timezone !== 'UTC' ? $context->timezone : null,
            );

            $selects[] = $query->getConnection()->raw($expression->getValue($query->getGrammar()).' as '.$query->getGrammar()->wrap($alias)); // @phpstan-ignore argument.type (grammar-wrapped alias around a Raw expression)
            // Group by the alias: MySQL's ONLY_FULL_GROUP_BY cannot match a select
            // expression to a GROUP BY expression once a binding is involved, and
            // every supported driver accepts an output-column alias here.
            $query->groupBy($alias);
            array_push($bindings, ...$expressionBindings);
        }

        foreach ($aggregates as $index => $aggregate) {
            $alias = 'a'.$index;
            $fn = AggregateType::from($aggregate->fn);

            if ($fn === AggregateType::Count) {
                $selects[] = $query->getConnection()->raw('COUNT(*) as '.$query->getGrammar()->wrap($alias)); // @phpstan-ignore argument.type (grammar-wrapped alias)

                continue;
            }

            $field = $definition->field((string) $aggregate->field) ?? throw new UnsupportedException(sprintf('Aggregate over "%s" reached the compiler unvalidated.', (string) $aggregate->field));
            $selects[] = $query->getConnection()->raw(Raw::aggregate($query, $fn->value, $this->groupColumn($query, $field, $context))->getValue($query->getGrammar()).' as '.$query->getGrammar()->wrap($alias)); // @phpstan-ignore argument.type (grammar-wrapped alias around a Raw expression)
        }

        $query->select($selects);

        if ($bindings !== []) {
            $base->addBinding($bindings, 'select');
        }

        return array_values($columns);
    }

    /**
     * The SQL column a scalar field groups or aggregates by: its own column qualified,
     * or the joined column when the path crosses a relation (§6).
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    protected function groupColumn(EloquentBuilder|QueryBuilder $query, Field $field, Context $context): string
    {
        $relations = $context->relations();
        $path = $relations->resolveField($query, $field)->path;
        $column = (string) $path->column;

        return $path->crossesRelation()
            ? $relations->join($query, $path).'.'.$column
            : RelationResolver::qualify($query, $column);
    }

    /**
     * The joined key and label columns a relation field groups by.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @return array{string, string}
     */
    protected function relationColumns(EloquentBuilder|QueryBuilder $query, RelationField $field, Context $context): array
    {
        if (! $query instanceof EloquentBuilder) {
            throw new UnsupportedException(sprintf('Grouping by "%s" needs an Eloquent builder; the source query is a plain query builder.', $field->getKey()));
        }

        $relations = $context->relations();
        $model = $query->getModel()::class;
        $resolved = $relations->resolve($model, $field->getPath());
        $related = $resolved->related ?? throw new UnsupportedException(sprintf('Group "%s" does not resolve to a relation on %s.', $field->getKey(), $model));
        $labelPath = $relations->labelPath($field, $model);

        return [
            $relations->join($query, $resolved->path).'.'.(new $related)->getKeyName(),
            $relations->join($query, $labelPath).'.'.$labelPath->column,
        ];
    }

    /**
     * Order buckets: by the first grain chronologically when there is one, else by the
     * first aggregate descending (D39 facets), then by group value with the null bucket
     * last (D53).
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  list<GroupSpec>  $groups
     * @param  list<AggregateSpec>  $aggregates
     * @param  list<string|null>  $columns  the group columns compile() returned
     */
    public function order(EloquentBuilder|QueryBuilder $query, array $groups, array $aggregates, array $columns): void
    {
        foreach ($groups as $index => $group) {
            if ($group->grain !== null) {
                $query->orderBy('g'.$index, 'asc');

                return;
            }
        }

        if ($aggregates !== []) {
            $query->orderBy('a0', 'desc');
        }

        foreach (array_keys($groups) as $index) {
            $column = $columns[$index] ?? null;

            if ($column !== null) {
                $query->orderBy(Raw::isNull($query, $column));
            }

            $query->orderBy('g'.$index, 'asc');
        }
    }

    /**
     * Turn a result row into a bucket: group values as refs, grains as period refs, measures as numbers.
     *
     * @param  list<GroupSpec>  $groups
     * @param  list<AggregateSpec>  $aggregates
     * @return array<string, mixed>
     */
    public function bucket(object $row, array $groups, array $aggregates, Definition $definition, Context $context): array
    {
        $bucket = [];

        foreach ($groups as $index => $group) {
            $raw = data_get($row, 'g'.$index);
            $field = $definition->field($group->key);

            $bucket[$group->key] = match (true) {
                $group->grain !== null => self::grainRef($raw, $group->grain),
                $field instanceof RelationField => self::relationRef($raw, data_get($row, 'g'.$index.'_label')),
                $field !== null => $field->serialize(self::nest($field->getPath(), $raw), $context),
                default => $raw,
            };
        }

        foreach ($aggregates as $index => $aggregate) {
            $value = data_get($row, 'a'.$index);
            $bucket[self::aggregateKey($aggregate)] = is_numeric($value) ? $value + 0 : $value;
        }

        return $bucket;
    }

    /**
     * A relation bucket's reference: id and label only — there is no entity to resolve a
     * url against in a grouped row (D56).
     *
     * @return array{id: int|string, label: string}|null
     */
    public static function relationRef(mixed $id, mixed $label): ?array
    {
        if ($id === null) {
            return null;
        }

        return ['id' => is_int($id) || is_string($id) ? $id : (string) json_encode($id), 'label' => is_scalar($label) ? (string) $label : ''];
    }

    /**
     * A one-value row shaped like the field's path, so serialize() reads it as usual.
     */
    public static function nest(string $path, mixed $value): object
    {
        $segments = explode('.', $path);
        $object = (object) [(string) array_pop($segments) => $value];

        foreach (array_reverse($segments) as $segment) {
            $object = (object) [$segment => $object];
        }

        return $object;
    }

    /**
     * @return array{id: string, label: string}|null
     */
    public static function grainRef(mixed $start, string $grain): ?array
    {
        if (! is_string($start) || $start === '') {
            return null;
        }

        $date = CarbonImmutable::parse($start, 'UTC');

        return ['id' => $start, 'label' => match ($grain) {
            'day' => $date->format('j M Y'),
            'week' => 'Week of '.$date->format('j M Y'),
            'month' => $date->format('M Y'),
            'quarter' => 'Q'.$date->quarter.' '.$date->format('Y'),
            default => $date->format('Y'),
        }];
    }

    /**
     * @param  array<string, Field>  $fields
     * @return list<Field>
     */
    public static function grouped(array $fields): array
    {
        return array_values($fields);
    }
}
