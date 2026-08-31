<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Datawell\Definition;
use Datawell\Enums\ValueShape;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Fields\Field;
use Datawell\Fields\RelationField;
use Datawell\Filters\Filter;
use Datawell\Query\FilterCondition;
use Datawell\Query\FilterGroup;
use Datawell\Query\SortSpec;
use Datawell\Relations\RelationResolver;
use Datawell\Sorts\Sort;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Turns a *validated* request into query clauses. Nothing here re-checks permissions —
 * the validator already guaranteed every key is visible and every value fits (D05, D09).
 */
class Compiler
{
    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    public function filters(EloquentBuilder|QueryBuilder $query, FilterGroup $group, Definition $definition, Context $context): void
    {
        if ($group->isEmpty()) {
            return;
        }

        $query->where(function (EloquentBuilder|QueryBuilder $nested) use ($group, $definition, $context): void {
            $this->group($nested, $group, $definition, $context);
        });
    }

    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    protected function group(EloquentBuilder|QueryBuilder $query, FilterGroup $group, Definition $definition, Context $context): void
    {
        foreach ($group->conditions as $condition) {
            $callback = function (EloquentBuilder|QueryBuilder $nested) use ($condition, $definition, $context): void {
                if ($condition instanceof FilterGroup) {
                    $this->group($nested, $condition, $definition, $context);
                } else {
                    $this->leaf($nested, $condition, $definition, $context);
                }
            };

            $group->boolean === 'or' ? $query->orWhere($callback) : $query->where($callback);
        }
    }

    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    protected function leaf(EloquentBuilder|QueryBuilder $query, FilterCondition $leaf, Definition $definition, Context $context): void
    {
        $filter = $definition->filters()[$leaf->filter] ?? throw new UnsupportedException(sprintf('Filter "%s" reached the compiler unvalidated.', $leaf->filter));
        $apply = $filter->getApply();

        if ($apply !== null) {
            $apply($query, $leaf->operator, $leaf->value);

            return;
        }

        $field = $filter->getField() ?? throw new UnsupportedException(sprintf('Filter "%s" has neither a field nor an apply().', $leaf->filter));
        $field->applyCondition($query, $leaf->operator, $leaf->value, $context);
    }

    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  array<string, Field>  $visibleFields
     */
    public function search(EloquentBuilder|QueryBuilder $query, string $search, array $visibleFields, Context $context): void
    {
        $searchable = array_values(array_filter(
            $visibleFields,
            static fn (Field $field): bool => $field->isSearchable() && ($field->type() === 'text' || $field instanceof RelationField),
        ));

        Search::apply($query, $search, $searchable, $context);
    }

    /**
     * Applies the sorts and appends the primary key as a tie-breaker (D39), returning the
     * effective ordering as [column|null, direction] pairs for cursor construction.
     *
     * A sort through a relation joins it (§6) and selects the joined column under a
     * private attribute so the cursor can read it back off the row.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  list<SortSpec>  $sorts
     * @return list<array{string|Expression, string, bool, string, list<mixed>}> column, direction, nullability, the row attribute holding the value and the column's bindings, ending with the key
     */
    public function sorts(EloquentBuilder|QueryBuilder $query, array $sorts, Definition $definition, string $keyName, Context $context): array
    {
        $order = [];

        foreach ($sorts as $index => $spec) {
            $sort = $definition->sorts()[$spec->key] ?? throw new UnsupportedException(sprintf('Sort "%s" reached the compiler unvalidated.', $spec->key));
            $apply = $sort->getApply();

            if ($apply !== null) {
                $apply($query, $spec->direction);

                continue;
            }

            $field = $sort->getField() ?? throw new UnsupportedException(sprintf('Sort "%s" has neither a field nor an apply().', $spec->key));
            [$column, $attribute, $nullable, $bindings] = $this->sortColumn($query, $field, $index, $context);

            if ($nullable) {
                $query->addBinding($bindings, 'order');
                $query->orderBy(Raw::isNull($query, $column));
            }

            $query->addBinding($bindings, 'order');
            $query->orderBy($column, $spec->direction);
            $order[] = [$column, $spec->direction, $nullable, $attribute, $bindings];
        }

        $keyColumn = RelationResolver::qualify($query, $keyName);
        $query->orderBy($keyColumn, 'asc');
        $order[] = [$keyColumn, 'asc', false, $keyName, []];

        return $order;
    }

    /**
     * The column a field sorts by, the attribute its value is read from, and whether
     * nulls need placing: a relation field sorts by its target's label, and anything
     * reached through a left join is nullable by construction (D53).
     *
     * An aggregate field sorts by its subselect and is read back under its own key (D55).
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @return array{string|Expression, string, bool, list<mixed>}
     */
    protected function sortColumn(EloquentBuilder|QueryBuilder $query, Field $field, int $index, Context $context): array
    {
        $relations = $context->relations();
        $aggregation = $field->getAggregation();

        if ($aggregation !== null) {
            $relations->selectAggregate($query, $field, $aggregation);
            [$expression, $bindings] = $relations->aggregate($query, $aggregation);

            return [$expression, $field->getKey(), $field->isNullable(), $bindings];
        }

        $path = $field instanceof RelationField && $query instanceof EloquentBuilder
            ? $relations->labelPath($field, $query->getModel()::class)
            : $relations->resolveField($query, $field)->path;
        $column = (string) $path->column;

        if (! $path->crossesRelation()) {
            return [RelationResolver::qualify($query, $column), $column, $field->isNullable(), []];
        }

        $joined = $relations->join($query, $path).'.'.$column;
        $attribute = 'dw_sort_'.$index;
        $query->addSelect($joined.' as '.$attribute);

        return [$joined, $attribute, true, []];
    }

    /**
     * Keyset predicate for "rows after the cursor" over the effective ordering:
     * (a > x) OR (a = x AND b > y) OR (a = x AND b = y AND pk > z) — with nulls last
     * on nullable columns (D53): a non-null cursor value is followed by greater values
     * and then by nulls; a null cursor value is followed only by other nulls.
     *
     * Every use of a column re-places that column's bindings first, so an aggregate
     * field's subselect binds correctly however often the predicate repeats it.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  list<array{string|Expression, string, bool, string, list<mixed>}>  $order
     * @param  list<mixed>  $values
     */
    public function after(EloquentBuilder|QueryBuilder $query, array $order, array $values): void
    {
        $query->where(static function (EloquentBuilder|QueryBuilder $outer) use ($order, $values): void {
            foreach ($order as $depth => [$column, $direction, $nullable, , $bindings]) {
                if ($values[$depth] === null) {
                    continue; // nothing sorts after a null at this level; deeper levels tie-break within the nulls
                }

                $outer->orWhere(static function (EloquentBuilder|QueryBuilder $branch) use ($order, $values, $depth, $column, $direction, $nullable, $bindings): void {
                    for ($i = 0; $i < $depth; $i++) {
                        $branch->addBinding($order[$i][4], 'where');
                        $values[$i] === null
                            ? $branch->whereNull($order[$i][0])
                            : $branch->where($order[$i][0], '=', $values[$i]);
                    }

                    $operator = $direction === 'desc' ? '<' : '>';

                    if ($nullable) {
                        $branch->where(static function (EloquentBuilder|QueryBuilder $level) use ($column, $operator, $values, $depth, $bindings): void {
                            $level->addBinding($bindings, 'where');
                            $level->where($column, $operator, $values[$depth]);
                            $level->addBinding($bindings, 'where');
                            $level->orWhereNull($column);
                        });
                    } else {
                        $branch->addBinding($bindings, 'where');
                        $branch->where($column, $operator, $values[$depth]);
                    }
                });
            }
        });
    }

    /**
     * Sort specs to run when the request names none: the source's resting order (D47).
     *
     * @return list<SortSpec>
     */
    public function defaultSorts(Definition $definition): array
    {
        $specs = [];

        foreach ($definition->source()->defaultSort() as $key => $direction) {
            /** @var 'asc'|'desc' $direction */
            $specs[] = new SortSpec($key, $direction);
        }

        return $specs;
    }

    /**
     * Filters with a declared default that the request did not mention (D35), as leaves.
     *
     * @param  array<string, Filter>  $visibleFilters
     * @return list<FilterCondition>
     */
    public function defaultedConditions(FilterGroup $requested, array $visibleFilters): array
    {
        $mentioned = array_map(static fn (FilterCondition $leaf): string => $leaf->filter, $requested->leaves());
        $defaults = [];

        foreach ($visibleFilters as $key => $filter) {
            $operator = $filter->getDefaultOperator();

            if ($operator === null || in_array($key, $mentioned, true)) {
                continue;
            }

            $hasValue = $operator->shape() !== ValueShape::None;
            $defaults[] = new FilterCondition($key, $operator, $hasValue ? $filter->getDefaultValue() : null, $hasValue);
        }

        return $defaults;
    }

    /**
     * @param  array<string, Sort>  $sorts
     * @return list<string>
     */
    public function sortKeys(array $sorts): array
    {
        return array_keys($sorts);
    }
}
