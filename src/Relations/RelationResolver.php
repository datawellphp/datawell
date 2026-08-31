<?php

declare(strict_types=1);

namespace Datawell\Relations;

use Closure;
use Datawell\DataSource;
use Datawell\Enums\Cardinality;
use Datawell\Exceptions\DefinitionException;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Fields\Field;
use Datawell\Fields\RelationField;
use Datawell\Registry;
use Datawell\Result\EntityRef;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The one seam through which every relation strategy runs (design doc §6, D50): path
 * resolution against the query's model, target-source resolution for relation fields
 * (D54), eager loading for display with the many-value cap (D21, D56), and — in the
 * compilation slices — `whereHas` for filter/search, aliased joins for sort and to-one
 * grouping, and correlated subselects for aggregate fields. One instance lives for one
 * request, so its caches (and later its join aliases) are request-scoped.
 */
class RelationResolver
{
    /** @var array<string, Resolved> */
    protected array $resolved = [];

    /** @var array<int, DataSource> */
    protected array $targets = [];

    /** @var array<int, array<string, string>> joined relation paths per base query, by alias */
    protected array $joins = [];

    public function __construct(
        protected Registry $registry,
        protected int $cap = 10,
        protected RelationIntrospector $introspector = new RelationIntrospector,
    ) {}

    /**
     * The per-row cap on many-values (D21): rows carry the first `cap` and a total.
     */
    public function cap(): int
    {
        return $this->cap;
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function resolve(string $model, string $path): Resolved
    {
        return $this->resolved[$model.'|'.$path] ??= $this->introspector->resolve($model, $path);
    }

    /**
     * A field's path resolved against the model behind a query. A plain query builder
     * has no relations: every path is a column there.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    public function resolveField(EloquentBuilder|QueryBuilder $query, Field $field): Resolved
    {
        if ($query instanceof EloquentBuilder) {
            return $this->resolve($query->getModel()::class, $field->getPath());
        }

        if ($field instanceof RelationField || ! $field->isColumn()) {
            throw new UnsupportedException(sprintf(
                'Field "%s" crosses a relation, but the source query is not an Eloquent builder; relations need a model.',
                $field->getKey(),
            ));
        }

        return new Resolved(Path::column($field->getPath()), null, null);
    }

    /**
     * The source a relation field's values are references into (D54): declared with
     * references(), or inferred when exactly one registered source declares the related
     * model. Anything else is a definition error, reported by the lint at boot.
     *
     * @param  class-string<Model>  $model  the model the field's source queries
     *
     * @throws DefinitionException
     */
    public function target(RelationField $field, string $model): DataSource
    {
        return $this->targets[spl_object_id($field)] ??= $this->resolveTarget($field, $model);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function resolveTarget(RelationField $field, string $model): DataSource
    {
        $declared = $field->getReferencedSourceKey();

        if ($declared !== null) {
            if (! $this->registry->has($declared)) {
                throw new DefinitionException(sprintf('field "%s" references source "%s", which is not registered', $field->getKey(), $declared));
            }

            return $this->registry->find($declared);
        }

        $resolved = $this->resolve($model, $field->getPath());

        if ($resolved->related === null) {
            throw new DefinitionException(sprintf('field "%s": "%s" is not a relation on %s', $field->getKey(), $field->getPath(), $model));
        }

        $candidates = $this->registry->withModel($resolved->related);

        return match (count($candidates)) {
            1 => $candidates[0],
            0 => throw new DefinitionException(sprintf(
                'field "%s" points at %s, which no registered source declares; register one or declare ->references(\'<source-key>\')',
                $field->getKey(),
                $resolved->related,
            )),
            default => throw new DefinitionException(sprintf(
                'field "%s" points at %s, which %s all declare; pick one with ->references(\'<source-key>\')',
                $field->getKey(),
                $resolved->related,
                implode(', ', array_map(static fn (DataSource $source): string => '"'.$source->key().'"', $candidates)),
            )),
        };
    }

    /**
     * The key of the source a relation field references, for the schema (D54).
     *
     * @param  class-string<Model>|null  $model
     */
    public function targetKey(RelationField $field, ?string $model): ?string
    {
        $declared = $field->getReferencedSourceKey();

        if ($declared !== null || $model === null) {
            return $declared;
        }

        try {
            return $this->target($field, $model)->key();
        } catch (DefinitionException) {
            return null;
        }
    }

    /**
     * Display strategy: eager load every relation the emitted fields read through, with
     * a per-parent limit and a total count for many-valued relation fields (D21, D56).
     *
     * @param  EloquentBuilder<covariant Model>  $query
     * @param  array<string, Field>  $fields
     */
    public function load(EloquentBuilder $query, array $fields): void
    {
        $with = [];
        $counts = [];
        $cap = $this->cap;

        foreach ($fields as $field) {
            $resolved = $this->resolveField($query, $field);

            if (! $resolved->path->crossesRelation()) {
                continue;
            }

            $relation = $resolved->path->relation();

            if ($field instanceof RelationField && $resolved->cardinality === Cardinality::Many) {
                if (count($resolved->path->relations) > 1) {
                    throw new UnsupportedException(sprintf('Field "%s": a to-many relation field must name a direct relation, not "%s".', $field->getKey(), $relation));
                }

                $with[$relation] = static function (mixed $related) use ($cap): void {
                    /** @var Relation<Model, Model, mixed> $related */
                    $related->orderBy($related->getRelated()->getQualifiedKeyName())->limit($cap);
                };
                $counts[] = $relation.' as '.self::totalAttribute($field);

                continue;
            }

            $with[] = $relation;
        }

        if ($with !== []) {
            $query->with($with);
        }

        if ($counts !== []) {
            $query->withCount($counts);
        }
    }

    /**
     * Filter/search strategy (§6): existence through the relation — `whereHas` or
     * `whereDoesntHave`, nested for dotted paths — so a to-many path never duplicates
     * parent rows. The constraint runs on the related model's builder.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  (Closure(EloquentBuilder<covariant Model>): mixed)|null  $constraint
     */
    public function has(EloquentBuilder|QueryBuilder $query, Path $path, ?Closure $constraint, bool $exists = true, string $boolean = 'and'): void
    {
        if (! $query instanceof EloquentBuilder) {
            throw new UnsupportedException(sprintf('Filtering through "%s" needs an Eloquent builder; the source query is a plain query builder.', $path->relation()));
        }

        $query->has($path->relation(), $exists ? '>=' : '<', 1, $boolean, $constraint);
    }

    /**
     * Sort/group strategy (§6): a left join per relation crossed, aliased deterministically
     * (`dw_signer`, `dw_document_owner`) and memoised per query so several capabilities
     * touching one relation share a single join. Joining forces an explicit `base.*`
     * select so joined columns never shadow the row's own. Returns the alias of the
     * path's last relation; qualify the column against it.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    public function join(EloquentBuilder|QueryBuilder $query, Path $path): string
    {
        if (! $query instanceof EloquentBuilder) {
            throw new UnsupportedException(sprintf('Joining "%s" needs an Eloquent builder; the source query is a plain query builder.', $path->relation()));
        }

        $base = $query->getQuery();
        $id = spl_object_id($base);

        if ($base->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        $chain = [];
        $alias = '';

        foreach ($path->relations as $depth => $segment) {
            $alias = 'dw_'.implode('_', [...array_slice($path->relations, 0, $depth), $segment]);
            $chain[] = $segment.' as '.$alias;
            $key = implode('.', array_slice($path->relations, 0, $depth + 1));

            if (isset($this->joins[$id][$key])) {
                continue;
            }

            // The join package's mixins are registered at runtime (D50); the first segment
            // joins from the base, deeper ones join "through" the already-joined prefix.
            $method = $depth === 0 ? 'leftJoinRelation' : 'leftJoinThroughRelation';
            $query->{$method}(implode('.', $chain));
            $this->joins[$id][$key] = $alias;
        }

        return $alias;
    }

    /**
     * A base-table column qualified against the query's table, so it stays unambiguous
     * once relations are joined. Plain query builders are left as written.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    public static function qualify(EloquentBuilder|QueryBuilder $query, string $column): string
    {
        return $query instanceof EloquentBuilder ? $query->qualifyColumn($column) : $column;
    }

    /**
     * Where a relation field's search and sort land: the target representation's label,
     * resolved against the related model and appended to the field's own path.
     *
     * @param  class-string<Model>  $model  the model the field's source queries
     */
    public function labelPath(RelationField $field, string $model): Path
    {
        $resolved = $this->resolve($model, $field->getPath());
        $target = $this->target($field, $model);

        if ($resolved->related === null) {
            throw new UnsupportedException(sprintf('Relation field "%s" does not resolve to a relation on %s.', $field->getKey(), $model));
        }

        return $resolved->path->then($this->resolve($resolved->related, $target->representation()->label)->path);
    }

    /**
     * The attribute a many-valued field's total is loaded under.
     */
    public static function totalAttribute(Field $field): string
    {
        return 'dw_'.$field->getKey().'_total';
    }

    /**
     * One entity as the reference the target source's representation defines (D21, D34).
     */
    public function ref(object $related, DataSource $target): EntityRef
    {
        $keyName = $related instanceof Model ? $related->getKeyName() : 'id';

        return $target->representation()->refFor($related, $keyName);
    }

    /**
     * A relation field's value on a fetched row: a reference, null, or `{items, total}`.
     *
     * @return EntityRef|array{items: list<EntityRef>, total: int}|null
     */
    public function valueOf(object $row, RelationField $field): EntityRef|array|null
    {
        if (! $row instanceof Model) {
            throw new UnsupportedException(sprintf('Relation field "%s" needs Eloquent rows to serialize.', $field->getKey()));
        }

        $target = $this->target($field, $row::class);
        $value = data_get($row, $field->getPath());

        if ($value instanceof Collection) {
            $total = $row->getAttribute(self::totalAttribute($field));
            $items = array_values($value->take($this->cap)->map(fn (object $related): EntityRef => $this->ref($related, $target))->all());

            return ['items' => $items, 'total' => is_numeric($total) ? (int) $total : $value->count()];
        }

        return $value === null ? null : $this->ref($value, $target);
    }
}
