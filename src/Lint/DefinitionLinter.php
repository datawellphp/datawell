<?php

declare(strict_types=1);

namespace Datawell\Lint;

use Datawell\Actions\Action;
use Datawell\Actions\LinkAction;
use Datawell\Actions\ServerAction;
use Datawell\DataSource;
use Datawell\Definition;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\OptionsStrategy;
use Datawell\Exceptions\DefinitionException;
use Datawell\Fields\Field;
use Datawell\Fields\RelationField;
use Datawell\Filters\Filter;
use Datawell\Operators\Operator;
use Datawell\Parameter;
use Datawell\Registry;
use Datawell\Relations\RelationIntrospector;
use Datawell\Relations\RelationResolver;
use Datawell\Sorts\Sort;
use Datawell\Support\Key;

/**
 * The boot-time definition lint (D20, D30): type-level mistakes are unwritable (D33);
 * everything knowable only after introspection or cross-referencing is caught here,
 * loudly, at authoring time.
 */
class DefinitionLinter
{
    /** @var list<string> */
    protected array $errors = [];

    /** @var list<string> */
    protected array $warnings = [];

    public function lint(Registry $registry): LintReport
    {
        $this->errors = [];
        $this->warnings = [];

        foreach ($registry->all() as $source) {
            $this->lintSource($source, $registry);
        }

        return new LintReport($this->errors, $this->warnings);
    }

    protected function lintSource(DataSource $source, Registry $registry): void
    {
        $key = $source->key();

        if (! Key::isValidSourceKey($key)) {
            $this->error($key, sprintf('key "%s" must be lowercase kebab-case (e.g. "document-signatures")', $key));
        }

        if (trim($source->description()) === '') {
            $this->warn($key, 'has no description — it is AI-facing prose, and a source without one is half-invisible to the smartest consumer');
        }

        $definition = $source->definition();

        foreach ($definition->duplicateKeys() as $duplicate) {
            $this->error($key, sprintf('declares %s more than once', $duplicate));
        }

        foreach ($definition->fields() as $field) {
            $this->lintField($key, $field, $definition, $registry);
        }

        foreach ($definition->filters() as $filter) {
            $this->lintFilter($key, $filter);
        }

        foreach ($definition->sorts() as $sort) {
            $this->lintSort($key, $sort);
        }

        foreach ($source->defaultSort() as $sortKey => $direction) {
            if (! isset($definition->sorts()[$sortKey])) {
                $this->error($key, sprintf('defaultSort references "%s", which is not a sort', $sortKey));
            }

            if (! in_array($direction, ['asc', 'desc'], true)) {
                $this->error($key, sprintf('defaultSort direction for "%s" must be "asc" or "desc"', $sortKey));
            }
        }

        foreach ($definition->actions() as $action) {
            $this->lintAction($key, $action);
        }

        foreach ($definition->parameters() as $parameter) {
            $this->lintParameter($key, $parameter, $registry);
        }
    }

    protected function lintField(string $source, Field $field, Definition $definition, Registry $registry): void
    {
        $key = $field->getKey();

        if (! Key::isValidItemKey($key)) {
            $this->error($source, sprintf('field key "%s" must be lowercase snake_case', $key));
        }

        if ($field instanceof RelationField && $definition->model() === null && ! $field->hasDeclaredCardinality()) {
            $this->error($source, sprintf(
                'field "%s" is a relation on a source with no #[Model]; declare ->cardinality() explicitly',
                $key,
            ));
        }

        $this->lintAggregation($source, $field, $definition);
        $this->lintPath($source, $field, $definition, $registry);

        if ($field->isMany()) {
            if ($field->isSortable()) {
                $this->error($source, sprintf(
                    '%s is a to-many path and cannot be sortable; declare an aggregate field (e.g. count) instead',
                    $key,
                ));
            }

            if ($field->isGroupable()) {
                $this->error($source, sprintf(
                    '%s is a to-many path and cannot be groupable; many-cardinality group-by is not supported (D25)',
                    $key,
                ));
            }
        }

        if (method_exists($field, 'getOptions')) {
            $options = $field->getOptions();

            if ($options !== null && $options->strategy === OptionsStrategy::Source) {
                $this->assertRegistered($source, $registry, $options->reference?->sourceKey, sprintf('field "%s" options', $key));
            }
        }
    }

    protected function lintFilter(string $source, Filter $filter): void
    {
        $key = $filter->getKey();
        $field = $filter->getField();

        if (! Key::isValidItemKey($key)) {
            $this->error($source, sprintf('filter key "%s" must be lowercase snake_case', $key));
        }

        if ($field === null) {
            if ($filter->getFieldKey() !== $key || ! $filter->hasApply()) {
                $this->error($source, sprintf(
                    'filter "%s" references field "%s", which does not exist; a custom filter must declare ->apply()',
                    $key,
                    $filter->getFieldKey(),
                ));
            }

            if ($filter->hasApply() && ($filter->getType() === null || $filter->getDeclaredOperators() === null)) {
                $this->error($source, sprintf('custom filter "%s" must declare ->type() and ->operators()', $key));
            }
        } elseif (! $field->isFilterable()) {
            $this->error($source, sprintf('filter "%s" is backed by field "%s", which is not filterable', $key, $field->getKey()));
        } else {
            $legal = $field->operators();
            $illegal = array_filter(
                $filter->getDeclaredOperators() ?? [],
                static fn (Operator $operator): bool => ! in_array($operator, $legal, true),
            );

            if ($illegal !== []) {
                $this->error($source, sprintf(
                    'filter "%s" widens its field\'s operators with %s; filters may narrow, never widen',
                    $key,
                    implode(', ', array_map(static fn (Operator $operator): string => $operator->value, $illegal)),
                ));
            }
        }
    }

    protected function lintSort(string $source, Sort $sort): void
    {
        $key = $sort->getKey();
        $field = $sort->getField();

        if (! Key::isValidItemKey($key)) {
            $this->error($source, sprintf('sort key "%s" must be lowercase snake_case', $key));
        }

        if ($field === null) {
            if ($sort->getFieldKey() !== $key || ! $sort->hasApply()) {
                $this->error($source, sprintf(
                    'sort "%s" references field "%s", which does not exist; a custom sort must declare ->apply()',
                    $key,
                    $sort->getFieldKey(),
                ));
            }
        } elseif (! $field->isSortable()) {
            $this->error($source, sprintf('sort "%s" is backed by field "%s", which is not sortable', $key, $field->getKey()));
        }
    }

    protected function lintAction(string $source, Action $action): void
    {
        $key = $action->getKey();
        $targets = $action->getTargets();

        if (! Key::isValidItemKey($key)) {
            $this->error($source, sprintf('action key "%s" must be lowercase snake_case', $key));
        }

        if ($targets === []) {
            $this->error($source, sprintf('action "%s" declares no targets', $key));
        }

        if (in_array(ActionTarget::Standalone, $targets, true) && count($targets) > 1) {
            $this->error($source, sprintf('action "%s" cannot combine standalone with row targets', $key));
        }

        if (! $action instanceof ServerAction && in_array(ActionTarget::QueryScope, $targets, true)) {
            $this->error($source, sprintf('action "%s" is a %s action and cannot target queryScope', $key, $action->kind()));
        }

        if ($action instanceof ServerAction) {
            if ($action->getHandler() === null) {
                $this->error($source, sprintf('action "%s" has no handler', $key));
            }

            if ($action->getDescription() === null) {
                $this->warn($source, sprintf('action "%s" has no description — it becomes an AI tool without one', $key));
            }

            foreach ($action->getParameters() as $parameter) {
                if (! Key::isValidItemKey($parameter->getKey())) {
                    $this->error($source, sprintf('action "%s" parameter key "%s" must be lowercase snake_case', $key, $parameter->getKey()));
                }
            }
        }

        if ($action instanceof LinkAction && ! $action->hasUrl()) {
            $this->error($source, sprintf('link action "%s" has no URL resolver', $key));
        }
    }

    protected function lintParameter(string $source, Parameter $parameter, Registry $registry): void
    {
        $key = $parameter->getKey();

        if (! Key::isValidItemKey($key)) {
            $this->error($source, sprintf('parameter key "%s" must be lowercase snake_case', $key));
        }

        $this->assertRegistered($source, $registry, $parameter->getReference()?->sourceKey, sprintf('parameter "%s"', $key));
    }

    /**
     * An aggregate field (D55) names one direct relation of the model, a column unless it
     * counts, and cannot be searched (its value is a number or a date, never text to scan).
     */
    protected function lintAggregation(string $source, Field $field, Definition $definition): void
    {
        $aggregation = $field->getAggregation();

        if ($aggregation === null) {
            return;
        }

        $key = $field->getKey();

        if ($field->isSearchable()) {
            $this->error($source, sprintf('field "%s" is an aggregate (%s) and cannot be searchable', $key, $aggregation->describe()));
        }

        if (str_contains($aggregation->relation, '.')) {
            $this->error($source, sprintf('field "%s" aggregates over "%s"; an aggregate spans one direct relation, not a path', $key, $aggregation->relation));

            return;
        }

        $model = $definition->model();

        if ($model === null) {
            return;
        }

        if ((new RelationIntrospector)->resolve($model, $aggregation->relation)->related === null) {
            $this->error($source, sprintf('field "%s" aggregates over "%s", which is not a relation on %s', $key, $aggregation->relation, $model));
        }
    }

    /**
     * Paths are checked against the model where there is one (D20, D54): a dotted path
     * must cross real relations, a relation field must end on a relation and resolve to
     * exactly one target source, and a to-many relation field must be a direct relation.
     */
    protected function lintPath(string $source, Field $field, Definition $definition, Registry $registry): void
    {
        $model = $definition->model();

        if ($model === null) {
            return;
        }

        if ($field->getAggregation() !== null) {
            return;
        }

        $key = $field->getKey();
        $relations = new RelationResolver($registry);
        $resolved = $relations->resolve($model, $field->getPath());
        $path = $resolved->path;

        if ($path->column !== null && str_contains($path->column, '.')) {
            $this->error($source, sprintf(
                'field "%s": "%s" is not a relation on %s (path "%s")',
                $key,
                explode('.', $path->column)[0],
                $resolved->related ?? $model,
                $field->getPath(),
            ));

            return;
        }

        if (! $field instanceof RelationField) {
            return;
        }

        if (! $path->endsOnRelation()) {
            $this->error($source, sprintf('field "%s": "%s" ends on a column; a relation field must name a relation', $key, $field->getPath()));

            return;
        }

        if ($field->isMany() && count($path->relations) > 1) {
            $this->error($source, sprintf('field "%s": a to-many relation field must name a direct relation, not "%s"', $key, $field->getPath()));
        }

        try {
            $relations->target($field, $model);
        } catch (DefinitionException $exception) {
            $this->error($source, $exception->getMessage());
        }
    }

    protected function assertRegistered(string $source, Registry $registry, ?string $referenced, string $where): void
    {
        if ($referenced !== null && ! $registry->has($referenced)) {
            $this->error($source, sprintf('%s references source "%s", which is not registered', $where, $referenced));
        }
    }

    protected function error(string $source, string $message): void
    {
        $this->errors[] = sprintf('[%s] %s', $source, $message);
    }

    protected function warn(string $source, string $message): void
    {
        $this->warnings[] = sprintf('[%s] %s', $source, $message);
    }
}
