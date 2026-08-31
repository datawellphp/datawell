<?php

declare(strict_types=1);

namespace Datawell;

use Carbon\CarbonImmutable;
use Datawell\Actions\Action;
use Datawell\Actions\ActionReport;
use Datawell\Actions\ActionRequest;
use Datawell\Actions\Runner;
use Datawell\Actions\ServerAction;
use Datawell\Actions\Target;
use Datawell\Compilation\Compiler;
use Datawell\Compilation\Cursor;
use Datawell\Compilation\Grouping;
use Datawell\Compilation\Raw;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\Confirmation;
use Datawell\Exceptions\SourceNotFoundException;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Channel;
use Datawell\Execution\Context;
use Datawell\Fields\Field;
use Datawell\Fields\RelationField;
use Datawell\Filters\Filter;
use Datawell\Query\FilterGroup;
use Datawell\Query\QueryRequest;
use Datawell\Relations\RelationResolver;
use Datawell\Result\BucketResult;
use Datawell\Result\EntityRef;
use Datawell\Result\PageMeta;
use Datawell\Result\Result;
use Datawell\Result\Serializer;
use Datawell\Timezone\TimezoneResolver;
use Datawell\Validation\ProvenanceResolver;
use Datawell\Validation\RequestValidator;
use Datawell\Validation\ValidationException;
use Datawell\Validation\ValidationReport;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The one enforcement point (D05). Every consumer — table, AI, charts, exports — hands
 * a QueryRequest to this pipeline; nothing else touches a query builder.
 */
class Executor
{
    public function __construct(
        protected Registry $registry,
        protected TimezoneResolver $timezones,
        protected RequestValidator $validator,
        protected ProvenanceResolver $provenance,
        protected Compiler $compiler,
        protected Grouping $grouping,
        protected Serializer $serializer,
        protected Repository $config,
        protected Runner $runner,
    ) {
        $this->provenance->using(fn (ValueReference $reference, mixed $value, array $parameters, Context $context): bool => $this->resolvesProvenance($reference, $value, $parameters, $context));
    }

    /**
     * Dry-run validation (D38): check a request against the current per-user schema
     * without executing. Hidden sources fail as not-found (D18).
     *
     * @param  QueryRequest|array<string, mixed>  $request
     *
     * @throws SourceNotFoundException
     * @throws ValidationException when the wire shape itself is malformed
     */
    public function validate(QueryRequest|array $request, Authenticatable $user, Channel $channel = Channel::Direct): ValidationReport
    {
        $request = $request instanceof QueryRequest ? $request : QueryRequest::fromArray($request);
        $source = $this->registry->findFor($request->source, $user);
        $context = $this->context($user, $channel);

        [$params, $errors] = $this->validator->validate($source, $request, $context);

        if ($errors === [] && $request->isAggregate()) {
            $errors = $this->driverErrors($source->query($params), $request, $source->definition(), $context);
        }

        return new ValidationReport($request, $errors);
    }

    /**
     * The pipeline (design doc §3.7): validate → base query → filters → search → sorts →
     * paginate → serialize. Returns rows, meta, and the request as it actually ran.
     *
     * @param  QueryRequest|array<string, mixed>  $request
     *
     * @throws SourceNotFoundException
     * @throws ValidationException
     */
    public function run(QueryRequest|array $request, Authenticatable $user, Channel $channel = Channel::Direct): Result|BucketResult
    {
        $request = $request instanceof QueryRequest ? $request : QueryRequest::fromArray($request);
        $source = $this->registry->findFor($request->source, $user);
        $context = $this->context($user, $channel);
        $definition = $source->definition();

        [$params, $errors] = $this->validator->validate($source, $request, $context);

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        $visibleFields = array_filter($definition->fields(), static fn (Field $field): bool => $field->isVisibleTo($user));
        $visibleFilters = array_filter($definition->filters(), static fn (Filter $filter): bool => $filter->isVisibleTo($user));

        $applied = $this->applied($request, $definition, $visibleFilters);

        $query = $source->query($params);
        $keyName = $this->keyNameOf($query, $definition->model());

        if ($applied->isAggregate()) {
            $driverErrors = $this->driverErrors($query, $applied, $definition, $context);

            if ($driverErrors !== []) {
                throw ValidationException::withErrors($driverErrors);
            }
        }

        $this->compiler->filters($query, $applied->filters, $definition, $context);

        if ($applied->search !== null && $applied->search !== '') {
            $this->compiler->search($query, $applied->search, $visibleFields, $context);
        }

        if ($applied->isAggregate()) {
            return $this->buckets($query, $applied, $definition, $context);
        }

        $order = $this->compiler->sorts($query, $applied->sorts, $definition, $keyName, $context);

        $size = $applied->page->size ?? $this->defaultPageSize();
        $total = null;

        if ($applied->page->isCursor()) {
            if ($applied->page->after !== null) {
                $this->compiler->after($query, $order, Cursor::decode($applied->page->after, count($order)));
            }
        } else {
            if ($applied->page->withTotal) {
                $total = (clone ($query instanceof EloquentBuilder ? $query->toBase() : $query))->getCountForPagination();
            }

            $query->offset(($applied->page->number - 1) * $size);
        }

        $query->limit($size + 1);

        $fields = $applied->select === null
            ? $visibleFields
            : array_intersect_key($visibleFields, array_flip($applied->select));

        if ($query instanceof EloquentBuilder) {
            $context->relations()->load($query, $fields);
        }

        /** @var list<object> $rows */
        $rows = $query->get()->all();
        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        $actions = $this->actionsFor($definition, $context);

        $serialized = array_map(
            fn (object $row): array => $this->serializer->row($row, $source, $definition, $fields, $actions, $context, $keyName),
            $rows,
        );

        $meta = $applied->page->isCursor()
            ? PageMeta::cursor($size, $hasMore, $hasMore ? $this->cursorFor(end($rows) ?: null, $order) : null)
            : PageMeta::offset($size, $applied->page->number, $hasMore, $total);

        return new Result($serialized, $meta, $applied);
    }

    /**
     * The reports path: group + aggregate, hard-capped with an explicit truncated flag (D39).
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    protected function buckets(EloquentBuilder|QueryBuilder $query, QueryRequest $applied, Definition $definition, Context $context): BucketResult
    {
        $this->grouping->compile($query, $applied->groupBy, $applied->aggregates, $definition, $context);
        $this->grouping->order($query, $applied->groupBy, $applied->aggregates);

        $cap = $this->bucketCap();
        $query->limit($cap + 1);

        /** @var list<object> $rows */
        $rows = $query->get()->all();
        $truncated = count($rows) > $cap;
        $rows = array_slice($rows, 0, $cap);

        $buckets = array_map(
            fn (object $row): array => $this->grouping->bucket($row, $applied->groupBy, $applied->aggregates, $definition, $context),
            $rows,
        );

        return new BucketResult($buckets, $truncated, $applied);
    }

    /**
     * Checks that need the connection: bucketing an instant by grain in a non-UTC
     * timezone is unsupported on SQLite and refused explicitly (D51).
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @return array<string, list<string>>
     */
    protected function driverErrors(EloquentBuilder|QueryBuilder $query, QueryRequest $request, Definition $definition, Context $context): array
    {
        $index = Grouping::unsupportedGrain($query, $request->groupBy, $definition, $context);

        if ($index === null) {
            return [];
        }

        $group = $request->groupBy[$index];

        return ['groupBy.'.$index.'.grain' => [sprintf(
            'Field "%s" cannot be bucketed by %s in %s on %s: this driver cannot convert timezones, so date-time grains require a UTC effective timezone here.',
            $group->key,
            (string) $group->grain,
            $context->timezone,
            Raw::driver($query),
        )]];
    }

    /**
     * Entity lookup by (source, id) through the source's permission-scoped query (D34):
     * the representation of one entity, or null when it is not in the caller's world.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function lookup(string $sourceKey, int|string $id, Authenticatable $user, array $parameters = [], Channel $channel = Channel::Direct): ?EntityRef
    {
        $source = $this->registry->findFor($sourceKey, $user);
        $context = $this->context($user, $channel);
        $request = new QueryRequest($sourceKey, $parameters);

        [$params, $errors] = $this->validator->validate($source, $request, $context);

        if ($errors !== []) {
            return null;
        }

        $query = $source->query($params);
        $keyName = $this->keyNameOf($query, $source->definition()->model());
        $row = $query->where(RelationResolver::qualify($query, $keyName), '=', $id)->first();

        return $row === null ? null : $this->serializer->ref($row, $source, $keyName);
    }

    /**
     * The write side (D40-D44): validate, gate by channel and consent, resolve the
     * target through the source's permission-scoped query, drop ineligible rows, run.
     * Failed validation is a request-level error — reports describe only what executed.
     *
     * @param  ActionRequest|array<string, mixed>  $request
     *
     * @throws SourceNotFoundException
     * @throws ValidationException
     */
    public function act(ActionRequest|array $request, Authenticatable $user, Channel $channel = Channel::Direct): ActionReport
    {
        $request = $request instanceof ActionRequest ? $request : ActionRequest::fromArray($request);
        $source = $this->registry->findFor($request->source, $user);
        $context = $this->context($user, $channel);
        $definition = $source->definition();

        // Gated and nonexistent actions are indistinguishable (D18, D37): not visible,
        // or humanOnly on a delegated channel, reads as unknown.
        $action = $this->actionsFor($definition, $context)[$request->action] ?? null;

        if ($action === null) {
            throw ValidationException::withErrors(['action' => [sprintf('Unknown action "%s".', $request->action)]]);
        }

        if (! $action instanceof ServerAction) {
            throw ValidationException::withErrors(['action' => [sprintf('Action "%s" is a %s action and does not execute server-side.', $request->action, $action->kind())]]);
        }

        $this->enforceConsent($action, $request, $context);

        [$params, $errors] = $this->validator->validate($source, new QueryRequest($request->source, $request->parameters), $context);

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        $declared = [];

        foreach ($action->getParameters() as $parameter) {
            $declared[$parameter->getKey()] = $parameter;
        }

        [$input, $inputErrors] = $this->validator->validateInput($declared, $request->input, $context);

        if ($inputErrors !== []) {
            throw ValidationException::withErrors($inputErrors);
        }

        $keyName = $this->keyNameOf($source->query($params), $definition->model());
        [$rows, $targeted, $skipped, $skippedTruncated] = $this->resolveTarget($action, $request, $source, $definition, $params, $context, $keyName);

        return $this->runner->run($action, $source, $rows, $input, $context, $keyName, $targeted, $skipped, $skippedTruncated);
    }

    /**
     * Consent, verified mechanically on delegated channels (D37): a confirmation-requiring
     * action needs an approval reference, and cannot run at all where no one can be asked.
     */
    protected function enforceConsent(ServerAction $action, ActionRequest $request, Context $context): void
    {
        if (! $context->channel->isDelegated() || $action->effectiveConfirmation() === Confirmation::Never) {
            return;
        }

        if (! $context->channel->isInteractive()) {
            throw ValidationException::withErrors(['action' => [sprintf('Action "%s" requires confirmation and cannot run on a non-interactive channel.', $action->getKey())]]);
        }

        if ($request->approval === null || trim($request->approval) === '') {
            throw ValidationException::withErrors(['approval' => [sprintf('Action "%s" requires user approval on this channel; include the approval reference.', $action->getKey())]]);
        }
    }

    /**
     * Resolve a target through the scoped query (D40) and re-enforce per-row
     * authorization (D43): out-of-scope ids skip as "Not found." — indistinguishable
     * from nonexistent ones — and ineligible rows skip as "Not allowed.", never
     * silently dropped or included.
     *
     * @return array{Collection<int, object>, int, list<array<string, mixed>>, bool}
     */
    protected function resolveTarget(ServerAction $action, ActionRequest $request, DataSource $source, Definition $definition, Params $params, Context $context, string $keyName): array
    {
        if ($action->isStandalone()) {
            if ($request->target !== null) {
                throw ValidationException::withErrors(['target' => [sprintf('Action "%s" is standalone and takes no target.', $action->getKey())]]);
            }

            return [new Collection, 0, [], false];
        }

        $target = $request->target ?? throw ValidationException::withErrors(['target' => [sprintf('Action "%s" needs a target.', $action->getKey())]]);
        $skipped = [];

        if ($target->isScope()) {
            if (! $action->hasTarget(ActionTarget::QueryScope)) {
                throw ValidationException::withErrors(['target' => [sprintf('Action "%s" does not accept a query target.', $action->getKey())]]);
            }

            $rows = $this->resolveScope($action, $target, $source, $definition, $params, $context, $keyName);
        } else {
            /** @var list<int|string> $ids */
            $ids = $target->ids;

            if (count($ids) > 1 && ! $action->hasTarget(ActionTarget::Many)) {
                throw ValidationException::withErrors(['target.ids' => [sprintf('Action "%s" accepts one row at a time.', $action->getKey())]]);
            }

            if (! $action->hasTarget(ActionTarget::Single) && ! $action->hasTarget(ActionTarget::Many)) {
                throw ValidationException::withErrors(['target.ids' => [sprintf('Action "%s" targets the matching query, not explicit ids.', $action->getKey())]]);
            }

            $query = $source->query($params);
            $rows = $query->whereIn(RelationResolver::qualify($query, $keyName), $ids)->get()->values();
            $foundKeys = $rows->map(static fn (object $row): mixed => data_get($row, $keyName))->all();

            foreach ($ids as $id) {
                if (! in_array($id, $foundKeys, false)) {
                    $skipped[] = ['id' => $id, 'reason' => 'Not found.'];
                }
            }
        }

        $targeted = $rows->count() + count($skipped);
        $eligible = [];

        foreach ($rows as $row) {
            if ($action->authorizes($context->user, $row)) {
                $eligible[] = $row;

                continue;
            }

            $skipped[] = $this->runner->entry($row, 'Not allowed.', $source, $keyName);
        }

        $max = $this->runner->maxFailures();

        return [
            new Collection($eligible),
            $targeted,
            array_slice($skipped, 0, $max),
            count($skipped) > $max,
        ];
    }

    /**
     * "All rows matching this view" (D40): the target query's filters and search are
     * validated against the per-user schema and compiled onto the scoped query — with
     * declared filter defaults folded in, exactly as the read side runs them — then
     * authorizeQuery narrows to eligible rows and `except` ids drop out.
     *
     * @return Collection<int, object>
     */
    protected function resolveScope(ServerAction $action, Target $target, DataSource $source, Definition $definition, Params $params, Context $context, string $keyName): Collection
    {
        /** @var QueryRequest $scope */
        $scope = $target->query;

        [, $errors] = $this->validator->validate($source, $scope, $context);

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        $user = $context->user;
        $visibleFields = array_filter($definition->fields(), static fn (Field $field): bool => $field->isVisibleTo($user));
        $visibleFilters = array_filter($definition->filters(), static fn (Filter $filter): bool => $filter->isVisibleTo($user));
        $applied = $this->applied($scope, $definition, $visibleFilters);

        $query = $source->query($params);
        $this->compiler->filters($query, $applied->filters, $definition, $context);

        if ($applied->search !== null && $applied->search !== '') {
            $this->compiler->search($query, $applied->search, $visibleFields, $context);
        }

        $narrow = $action->getAuthorizeQuery();

        if ($narrow !== null) {
            $query = $narrow($query) ?? $query;
        }

        if ($target->except !== []) {
            $query->whereNotIn(RelationResolver::qualify($query, $keyName), $target->except);
        }

        return $query->get()->values();
    }

    /**
     * The remainder of a many-valued field beyond the row cap (D39, D56): the references
     * of one entity's related items, paged in target-key order through the entity's own
     * scoped query. Null when the entity is not in the caller's world; validation errors
     * for an unknown field or a bad page as usual.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $page
     *
     * @throws SourceNotFoundException
     * @throws ValidationException
     */
    public function values(string $sourceKey, int|string $id, string $fieldKey, Authenticatable $user, array $parameters = [], array $page = [], Channel $channel = Channel::Direct): ?Result
    {
        $source = $this->registry->findFor($sourceKey, $user);
        $context = $this->context($user, $channel);
        $request = QueryRequest::fromArray(['source' => $sourceKey, 'parameters' => $parameters, 'page' => $page]);
        $definition = $source->definition();

        [$params, $errors] = $this->validator->validate($source, $request, $context);

        $field = $definition->field($fieldKey);

        if ($field === null || ! $field->isVisibleTo($user) || ! $field instanceof RelationField || ! $field->isMany()) {
            $errors['field'] = [sprintf('Unknown field "%s".', $fieldKey)];
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        /** @var RelationField $field */
        $query = $source->query($params);

        if (! $query instanceof EloquentBuilder) {
            throw new UnsupportedException(sprintf('values() over "%s" needs an Eloquent builder; the source query is a plain query builder.', $fieldKey));
        }

        $keyName = $this->keyNameOf($query, $definition->model());
        $row = $query->where(RelationResolver::qualify($query, $keyName), '=', $id)->first();

        if ($row === null) {
            return null;
        }

        $relations = $context->relations();
        $target = $relations->target($field, $row::class);
        $relation = $row->{$relations->resolve($row::class, $field->getPath())->path->relation()}();

        if (! $relation instanceof Relation) {
            throw new UnsupportedException(sprintf('Field "%s" does not resolve to a relation on %s.', $fieldKey, $row::class));
        }

        $relatedKey = $relation->getRelated()->getQualifiedKeyName();
        $items = $relation->getQuery()->orderBy($relatedKey, 'asc');

        $size = $request->page->size ?? $this->defaultPageSize();
        $total = null;

        if ($request->page->isCursor()) {
            if ($request->page->after !== null) {
                $items->where($relatedKey, '>', Cursor::decode($request->page->after, 1)[0]);
            }
        } else {
            if ($request->page->withTotal) {
                $total = (clone $items->toBase())->getCountForPagination();
            }

            $items->offset(($request->page->number - 1) * $size);
        }

        /** @var list<Model> $rows */
        $rows = $items->limit($size + 1)->get()->all();
        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        $last = end($rows);

        $meta = $request->page->isCursor()
            ? PageMeta::cursor($size, $hasMore, $hasMore && $last !== false ? Cursor::encode([$last->getKey()]) : null)
            : PageMeta::offset($size, $request->page->number, $hasMore, $total);

        return new Result(
            array_map(fn (Model $item): array => $relations->ref($item, $target)->toArray(), $rows),
            $meta,
            $request,
        );
    }

    public function context(Authenticatable $user, Channel $channel = Channel::Direct): Context
    {
        $timezone = $this->timezones->resolve($user);

        return new Context(
            $user,
            $channel,
            $timezone,
            CarbonImmutable::now(new DateTimeZone($timezone)),
            new RelationResolver($this->registry, $this->valuesCap()),
        );
    }

    /**
     * Provenance (D23): the value must exist within the caller's scoped view of the
     * referenced source — resolved as a lookup, bindings carried across.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function resolvesProvenance(ValueReference $reference, mixed $value, array $parameters, Context $context): bool
    {
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }

        $bound = [];

        foreach ($reference->bindings as $theirs => $ours) {
            if (array_key_exists($ours, $parameters)) {
                $bound[$theirs] = $parameters[$ours];
            }
        }

        try {
            return $this->lookup($reference->sourceKey, $value, $context->user, $bound, $context->channel) !== null;
        } catch (SourceNotFoundException) {
            return false;
        }
    }

    /**
     * The request as it will run: defaulted filters folded into the root group and the
     * resting sort applied when none was named (D35, D47).
     *
     * @param  array<string, Filter>  $visibleFilters
     */
    protected function applied(QueryRequest $request, Definition $definition, array $visibleFilters): QueryRequest
    {
        $defaults = $this->compiler->defaultedConditions($request->filters, $visibleFilters);
        $filters = $defaults === []
            ? $request->filters
            : new FilterGroup('and', $request->filters->isEmpty() ? $defaults : [$request->filters, ...$defaults]);

        return new QueryRequest(
            $request->source,
            $request->parameters,
            $request->search,
            $filters,
            $request->sorts === [] ? $this->compiler->defaultSorts($definition) : $request->sorts,
            $request->groupBy,
            $request->aggregates,
            $request->select,
            $request->page,
            $request->pageProvided,
        );
    }

    /**
     * Actions that exist for this user on this channel (D37: humanOnly never reaches a delegated conduit).
     *
     * @return array<string, Action>
     */
    protected function actionsFor(Definition $definition, Context $context): array
    {
        return array_filter(
            $definition->actions(),
            static fn (Action $action): bool => $action->isVisibleTo($context->user)
                && ! ($action->isHumanOnly() && $context->channel->isDelegated()),
        );
    }

    /**
     * @param  list<array{string|Expression, string, bool, string, list<mixed>}>  $order
     */
    protected function cursorFor(?object $row, array $order): ?string
    {
        if ($row === null) {
            return null;
        }

        return Cursor::encode(array_map(static function (array $sort) use ($row): mixed {
            $value = data_get($row, $sort[3]);

            return $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)->setTimezone('UTC')->format('Y-m-d H:i:s')
                : $value;
        }, $order));
    }

    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     * @param  class-string<Model>|null  $model
     */
    protected function keyNameOf(EloquentBuilder|QueryBuilder $query, ?string $model): string
    {
        if ($query instanceof EloquentBuilder) {
            return $query->getModel()->getKeyName();
        }

        return $model === null ? 'id' : (new $model)->getKeyName();
    }

    protected function valuesCap(): int
    {
        $cap = $this->config->get('datawell.values.max');

        return is_int($cap) && $cap > 0 ? $cap : 10;
    }

    protected function bucketCap(): int
    {
        $cap = $this->config->get('datawell.buckets.max');

        return is_int($cap) && $cap > 0 ? $cap : 1000;
    }

    protected function defaultPageSize(): int
    {
        $size = $this->config->get('datawell.page.default');

        return is_int($size) && $size > 0 ? $size : 25;
    }
}
