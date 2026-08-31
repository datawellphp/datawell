<?php

declare(strict_types=1);

namespace Datawell;

use Carbon\CarbonImmutable;
use Datawell\Actions\Action;
use Datawell\Compilation\Compiler;
use Datawell\Compilation\Cursor;
use Datawell\Exceptions\SourceNotFoundException;
use Datawell\Execution\Channel;
use Datawell\Execution\Context;
use Datawell\Fields\Field;
use Datawell\Filters\Filter;
use Datawell\Query\FilterGroup;
use Datawell\Query\QueryRequest;
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
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
        protected Serializer $serializer,
        protected Repository $config,
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

        [, $errors] = $this->validator->validate($source, $request, $context);

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
    public function run(QueryRequest|array $request, Authenticatable $user, Channel $channel = Channel::Direct): Result
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

        $this->compiler->filters($query, $applied->filters, $definition, $context);

        if ($applied->search !== null && $applied->search !== '') {
            $this->compiler->search($query, $applied->search, $visibleFields);
        }

        $order = $this->compiler->sorts($query, $applied->sorts, $definition, $keyName);

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

        /** @var list<object> $rows */
        $rows = $query->get()->all();
        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        $fields = $applied->select === null
            ? $visibleFields
            : array_intersect_key($visibleFields, array_flip($applied->select));
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
        $row = $query->where($keyName, '=', $id)->first();

        return $row === null ? null : $this->serializer->ref($row, $source, $keyName);
    }

    public function context(Authenticatable $user, Channel $channel = Channel::Direct): Context
    {
        $timezone = $this->timezones->resolve($user);

        return new Context($user, $channel, $timezone, CarbonImmutable::now(new DateTimeZone($timezone)));
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
     * @param  list<array{string, string, bool}>  $order
     */
    protected function cursorFor(?object $row, array $order): ?string
    {
        if ($row === null) {
            return null;
        }

        return Cursor::encode(array_map(static function (array $sort) use ($row): mixed {
            $value = data_get($row, $sort[0]);

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

    protected function defaultPageSize(): int
    {
        $size = $this->config->get('datawell.page.default');

        return is_int($size) && $size > 0 ? $size : 25;
    }
}
