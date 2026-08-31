<?php

declare(strict_types=1);

namespace Datawell\Validation;

use Datawell\DataSource;
use Datawell\Definition;
use Datawell\Enums\AggregateType;
use Datawell\Enums\Grain;
use Datawell\Execution\Context;
use Datawell\Fields\Field;
use Datawell\Filters\Filter;
use Datawell\Parameter;
use Datawell\Params;
use Datawell\Query\Errors;
use Datawell\Query\FilterCondition;
use Datawell\Query\FilterGroup;
use Datawell\Query\QueryRequest;
use Datawell\Sorts\Sort;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\Factory;

/**
 * The enforcement pipeline's front half (D31 order): parameter rules → provenance →
 * authorize(user, params) → request vs the per-user schema. Every rejection is explicit;
 * hidden things are indistinguishable from nonexistent ones (D17, D18).
 */
class RequestValidator
{
    public const int MAX_DEPTH = 2;

    public function __construct(
        protected Factory $validator,
        protected Repository $config,
        protected ProvenanceResolver $provenance,
    ) {}

    /**
     * @return array{Params, array<string, list<string>>}
     */
    public function validate(DataSource $source, QueryRequest $request, Context $context): array
    {
        $errors = new Errors;
        $definition = $source->definition();

        $params = $this->validateParameters($source, $definition, $request, $context, $errors);

        if ($errors->any()) {
            return [$params, $errors->all()];
        }

        if (! $source->authorize($context->user, $params)) {
            $this->maskAsInvalid($definition, $errors);

            return [$params, $errors->all()];
        }

        $this->validateAgainstSchema($definition, $request, $context, $errors);

        return [$params, $errors->all()];
    }

    /**
     * Rules, then provenance — the value must exist within the caller's scoped view
     * of the referenced source (D23). Defaults fill optional parameters.
     */
    protected function validateParameters(DataSource $source, Definition $definition, QueryRequest $request, Context $context, Errors $errors): Params
    {
        return $this->validateParameterSet($definition->parameters(), $request->parameters, $context, $errors, 'parameters');
    }

    /**
     * Action input rides the same Parameter machinery (D37): rules, defaults, provenance.
     *
     * @param  array<string, Parameter>  $declared
     * @param  array<string, mixed>  $values
     * @return array{Params, array<string, list<string>>}
     */
    public function validateInput(array $declared, array $values, Context $context): array
    {
        $errors = new Errors;
        $params = $this->validateParameterSet($declared, $values, $context, $errors, 'input');

        return [$params, $errors->all()];
    }

    /**
     * @param  array<string, Parameter>  $declared
     * @param  array<string, mixed>  $given
     */
    protected function validateParameterSet(array $declared, array $given, Context $context, Errors $errors, string $path): Params
    {
        foreach (array_keys($given) as $key) {
            if (! isset($declared[$key])) {
                $errors->add($path.'.'.$key, sprintf('Unknown %s "%s".', $path === 'input' ? 'input' : 'parameter', $key));
            }
        }

        $values = [];
        $rules = [];

        foreach ($declared as $key => $parameter) {
            if (array_key_exists($key, $given)) {
                $values[$key] = $given[$key];
            } elseif ($parameter->hasDefault()) {
                $values[$key] = $parameter->getDefault();
            }

            $rules[$key] = [$parameter->isRequired() ? 'required' : 'nullable', ...$parameter->getRules()];
        }

        $laravel = $this->validator->make($values, $rules);

        if ($laravel->fails()) {
            foreach ($laravel->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $errors->add($path.'.'.$key, $message);
                }
            }

            return Params::make($values);
        }

        foreach ($declared as $key => $parameter) {
            $reference = $parameter->getReference();

            if ($reference === null || ! array_key_exists($key, $values) || $values[$key] === null) {
                continue;
            }

            if (! $this->provenance->exists($reference, $values[$key], $values, $context)) {
                $errors->add($path.'.'.$key, sprintf('Invalid %s.', $key));
            }
        }

        return Params::make($values);
    }

    /**
     * A failed authorize() wears the same shape as an invalid parameter, so "gated" and
     * "nonexistent" are indistinguishable to a prober (D31).
     */
    protected function maskAsInvalid(Definition $definition, Errors $errors): void
    {
        foreach ($definition->parameters() as $key => $parameter) {
            if ($parameter->getReference() !== null) {
                $errors->add('parameters.'.$key, sprintf('Invalid %s.', $key));

                return;
            }
        }

        $first = array_key_first($definition->parameters());
        $errors->add($first === null ? 'parameters' : 'parameters.'.$first, $first === null ? 'Invalid parameters.' : sprintf('Invalid %s.', $first));
    }

    protected function validateAgainstSchema(Definition $definition, QueryRequest $request, Context $context, Errors $errors): void
    {
        $user = $context->user;
        $fields = array_filter($definition->fields(), static fn (Field $field): bool => $field->isVisibleTo($user));
        $filters = array_filter($definition->filters(), static fn (Filter $filter): bool => $filter->isVisibleTo($user));
        $sorts = array_filter($definition->sorts(), static fn (Sort $sort): bool => $sort->isVisibleTo($user));

        if ($request->filters->depth() > self::MAX_DEPTH) {
            $errors->add('filters', 'Filter groups may nest at most two levels.');
        } else {
            $this->validateGroup($request->filters, 'filters', $filters, $errors);
        }

        foreach ($request->sorts as $index => $sort) {
            if (! isset($sorts[$sort->key])) {
                $errors->add('sorts.'.$index.'.key', sprintf('Unknown sort "%s".', $sort->key));
            }
        }

        foreach ($request->select ?? [] as $index => $key) {
            if (! isset($fields[$key])) {
                $errors->add('select.'.$index, sprintf('Unknown field "%s".', $key));
            }
        }

        foreach ($request->groupBy as $index => $group) {
            $field = $fields[$group->key] ?? null;

            if ($field === null) {
                $errors->add('groupBy.'.$index.'.key', sprintf('Unknown field "%s".', $group->key));

                continue;
            }

            if (! $field->isGroupable()) {
                $errors->add('groupBy.'.$index.'.key', sprintf('Field "%s" is not groupable.', $group->key));

                continue;
            }

            if ($group->grain !== null) {
                $grains = method_exists($field, 'getGrains') ? $field->getGrains() : [];
                $allowed = array_map(static fn (Grain $grain): string => $grain->value, $grains);

                if (! in_array($group->grain, $allowed, true)) {
                    $errors->add('groupBy.'.$index.'.grain', $allowed === []
                        ? sprintf('Field "%s" cannot be bucketed by grain.', $group->key)
                        : sprintf('Grain "%s" is not available for "%s"; expected one of %s.', $group->grain, $group->key, implode(', ', $allowed)));
                }
            }
        }

        foreach ($request->aggregates as $index => $aggregate) {
            $fn = AggregateType::from($aggregate->fn);

            if ($fn === AggregateType::Count) {
                if ($aggregate->field !== null) {
                    $errors->add('aggregates.'.$index.'.field', 'The count aggregate takes no field.');
                }

                continue;
            }

            $field = $aggregate->field === null ? null : ($fields[$aggregate->field] ?? null);

            if ($field === null) {
                $errors->add('aggregates.'.$index.'.field', sprintf('Aggregate "%s" needs a field.', $aggregate->fn));
            } elseif (! in_array($fn, $field->getAggregates(), true)) {
                $errors->add('aggregates.'.$index.'.fn', sprintf('Aggregate "%s" is not available for "%s".', $aggregate->fn, $aggregate->field));
            }
        }

        if ($request->aggregates === [] && $request->groupBy !== []) {
            $errors->add('aggregates', 'A grouped request needs at least one aggregate.');
        }

        if ($request->isAggregate()) {
            foreach (['sorts' => $request->sorts !== [], 'select' => $request->select !== null, 'page' => $request->pageProvided] as $key => $present) {
                if ($present) {
                    $errors->add($key, sprintf('A grouped request does not accept "%s"; buckets are ordered and capped by the executor.', $key));
                }
            }
        }

        $ceiling = $this->pageCeiling($context);

        if ($request->page->size !== null && $request->page->size > $ceiling) {
            $errors->add('page.size', sprintf('Page size may not exceed %d.', $ceiling));
        }
    }

    /**
     * @param  array<string, Filter>  $filters  the filters visible to this user
     */
    protected function validateGroup(FilterGroup $group, string $path, array $filters, Errors $errors): void
    {
        foreach ($group->conditions as $index => $condition) {
            $childPath = $path.'.conditions.'.$index;

            if ($condition instanceof FilterGroup) {
                $this->validateGroup($condition, $childPath, $filters, $errors);

                continue;
            }

            $this->validateLeaf($condition, $childPath, $filters, $errors);
        }
    }

    /**
     * The four mechanical checks (D09): key visible → operator legal → shape correct → rules pass.
     *
     * @param  array<string, Filter>  $filters
     */
    protected function validateLeaf(FilterCondition $leaf, string $path, array $filters, Errors $errors): void
    {
        $filter = $filters[$leaf->filter] ?? null;

        if ($filter === null) {
            $errors->add($path.'.filter', sprintf('Unknown filter "%s".', $leaf->filter));

            return;
        }

        if (! in_array($leaf->operator, $filter->getOperators(), true)) {
            $errors->add($path.'.operator', sprintf('Operator "%s" is not valid for filter "%s".', $leaf->operator->value, $leaf->filter));

            return;
        }

        $problem = ValueShapes::check($leaf->operator, $filter->getType() ?? 'text', $leaf->value, $leaf->hasValue);

        if ($problem !== null) {
            $errors->add($path.'.value', $problem);

            return;
        }

        if ($filter->getRules() !== [] && $leaf->hasValue) {
            $laravel = $this->validator->make(['value' => $leaf->value], ['value' => $filter->getRules()]);

            foreach ($laravel->errors()->get('value') as $message) {
                $errors->add($path.'.value', is_string($message) ? $message : 'The value is invalid.');
            }
        }
    }

    protected function pageCeiling(Context $context): int
    {
        $key = $context->channel->isDelegated() ? 'datawell.page.max_delegated' : 'datawell.page.max';
        $ceiling = $this->config->get($key);

        return is_int($ceiling) && $ceiling > 0 ? $ceiling : 100;
    }
}
