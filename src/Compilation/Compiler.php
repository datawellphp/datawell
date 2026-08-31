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
use Datawell\Sorts\Sort;
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
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  list<SortSpec>  $sorts
     * @return list<array{string, string, bool}> column, direction and nullability, ending with the key
     */
    public function sorts(EloquentBuilder|QueryBuilder $query, array $sorts, Definition $definition, string $keyName): array
    {
        $order = [];

        foreach ($sorts as $spec) {
            $sort = $definition->sorts()[$spec->key] ?? throw new UnsupportedException(sprintf('Sort "%s" reached the compiler unvalidated.', $spec->key));
            $apply = $sort->getApply();

            if ($apply !== null) {
                $apply($query, $spec->direction);

                continue;
            }

            $field = $sort->getField() ?? throw new UnsupportedException(sprintf('Sort "%s" has neither a field nor an apply().', $spec->key));

            if (! $field->isColumn()) {
                throw new UnsupportedException(sprintf('Sort "%s" is relation-backed; relation sorting lands in Phase 3.', $spec->key));
            }

            if ($field->isNullable()) {
                $query->orderBy(Raw::isNull($query, $field->getPath()));
            }

            $query->orderBy($field->getPath(), $spec->direction);
            $order[] = [$field->getPath(), $spec->direction, $field->isNullable()];
        }

        $query->orderBy($keyName, 'asc');
        $order[] = [$keyName, 'asc', false];

        return $order;
    }

    /**
     * Keyset predicate for "rows after the cursor" over the effective ordering:
     * (a > x) OR (a = x AND b > y) OR (a = x AND b = y AND pk > z) — with nulls last
     * on nullable columns (D53): a non-null cursor value is followed by greater values
     * and then by nulls; a null cursor value is followed only by other nulls.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  list<array{string, string, bool}>  $order
     * @param  list<mixed>  $values
     */
    public function after(EloquentBuilder|QueryBuilder $query, array $order, array $values): void
    {
        $query->where(static function (EloquentBuilder|QueryBuilder $outer) use ($order, $values): void {
            foreach ($order as $depth => [$column, $direction, $nullable]) {
                if ($values[$depth] === null) {
                    continue; // nothing sorts after a null at this level; deeper levels tie-break within the nulls
                }

                $outer->orWhere(static function (EloquentBuilder|QueryBuilder $branch) use ($order, $values, $depth, $column, $direction, $nullable): void {
                    for ($i = 0; $i < $depth; $i++) {
                        $values[$i] === null
                            ? $branch->whereNull($order[$i][0])
                            : $branch->where($order[$i][0], '=', $values[$i]);
                    }

                    $operator = $direction === 'desc' ? '<' : '>';

                    if ($nullable) {
                        $branch->where(static function (EloquentBuilder|QueryBuilder $level) use ($column, $operator, $values, $depth): void {
                            $level->where($column, $operator, $values[$depth])->orWhereNull($column);
                        });
                    } else {
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
