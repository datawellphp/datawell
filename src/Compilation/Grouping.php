<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Carbon\CarbonImmutable;
use Datawell\Definition;
use Datawell\Enums\AggregateType;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Fields\Field;
use Datawell\Query\AggregateSpec;
use Datawell\Query\GroupSpec;
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
     * Compile the grouped select; returns the alias map used to build buckets.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  list<GroupSpec>  $groups
     * @param  list<AggregateSpec>  $aggregates
     */
    public function compile(EloquentBuilder|QueryBuilder $query, array $groups, array $aggregates, Definition $definition, Context $context): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $selects = [];
        $bindings = [];

        foreach ($groups as $index => $group) {
            $field = $definition->field($group->key) ?? throw new UnsupportedException(sprintf('Group "%s" reached the compiler unvalidated.', $group->key));

            if (! $field->isColumn()) {
                throw new UnsupportedException(sprintf('Group "%s" is relation-backed; relation grouping lands in Phase 3.', $group->key));
            }

            $alias = 'g'.$index;

            if ($group->grain === null) {
                $selects[] = $field->getPath().' as '.$alias;
                $query->groupBy($field->getPath());

                continue;
            }

            [$expression, $expressionBindings] = Raw::grain(
                $query,
                $field->getPath(),
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
            $selects[] = $query->getConnection()->raw(Raw::aggregate($query, $fn->value, $field->getPath())->getValue($query->getGrammar()).' as '.$query->getGrammar()->wrap($alias)); // @phpstan-ignore argument.type (grammar-wrapped alias around a Raw expression)
        }

        $query->select($selects);

        if ($bindings !== []) {
            $base->addBinding($bindings, 'select');
        }
    }

    /**
     * Order buckets: by the first grain chronologically when there is one, else by the first aggregate descending (D39 facets).
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  list<GroupSpec>  $groups
     * @param  list<AggregateSpec>  $aggregates
     */
    public function order(EloquentBuilder|QueryBuilder $query, array $groups, array $aggregates): void
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
                $field !== null => $field->serialize((object) [$field->getPath() => $raw], $context),
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
