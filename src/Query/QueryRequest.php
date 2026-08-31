<?php

declare(strict_types=1);

namespace Datawell\Query;

use Datawell\Enums\AggregateType;
use Datawell\Operators\Operator;
use Datawell\Validation\ValidationException;

/**
 * The single wire protocol (D05). `fromArray()` parses the shape strictly — unknown
 * keys, malformed nodes and unknown operators are rejected here, before any source is
 * consulted; whether the keys *mean* anything for this user is the validator's job.
 */
final class QueryRequest
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<SortSpec>  $sorts
     * @param  list<GroupSpec>  $groupBy
     * @param  list<AggregateSpec>  $aggregates
     * @param  list<string>|null  $select
     */
    public function __construct(
        public readonly string $source,
        public readonly array $parameters = [],
        public readonly ?string $search = null,
        public readonly FilterGroup $filters = new FilterGroup,
        public readonly array $sorts = [],
        public readonly array $groupBy = [],
        public readonly array $aggregates = [],
        public readonly ?array $select = null,
        public readonly PageSpec $page = new PageSpec,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $errors = new Errors;
        $allowed = ['source', 'parameters', 'search', 'filters', 'sorts', 'groupBy', 'aggregates', 'select', 'page'];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors->add((string) $key, sprintf('Unknown request key "%s".', $key));
            }
        }

        $source = $data['source'] ?? null;

        if (! is_string($source) || $source === '') {
            $errors->add('source', 'A source key is required.');
            $source = '';
        }

        $parameters = $data['parameters'] ?? [];

        if (! is_array($parameters)) {
            $errors->add('parameters', 'Parameters must be an object.');
            $parameters = [];
        }

        $search = $data['search'] ?? null;

        if ($search !== null && ! is_string($search)) {
            $errors->add('search', 'Search must be a string.');
            $search = null;
        }

        $filters = isset($data['filters']) ? self::parseGroup($data['filters'], 'filters', $errors, root: true) : new FilterGroup;
        $sorts = self::parseSorts($data['sorts'] ?? [], $errors);
        $groupBy = self::parseGroups($data['groupBy'] ?? [], $errors);
        $aggregates = self::parseAggregates($data['aggregates'] ?? [], $errors);
        $select = self::parseSelect($data['select'] ?? null, $errors);
        $page = self::parsePage($data['page'] ?? [], $errors);

        $errors->throwIfAny();

        /** @var array<string, mixed> $parameters */
        return new self($source, $parameters, $search, $filters, $sorts, $groupBy, $aggregates, $select, $page);
    }

    private static function parseGroup(mixed $node, string $path, Errors $errors, bool $root = false): FilterGroup
    {
        if (! is_array($node)) {
            $errors->add($path, 'A filter group must be an object with "conditions".');

            return new FilterGroup;
        }

        $boolean = $node['boolean'] ?? ($root ? 'and' : null);

        if (! in_array($boolean, ['and', 'or'], true)) {
            $errors->add($path.'.boolean', 'A filter group\'s "boolean" must be "and" or "or".');
            $boolean = 'and';
        }

        $conditions = $node['conditions'] ?? null;

        if (! is_array($conditions) || ! array_is_list($conditions)) {
            $errors->add($path.'.conditions', 'A filter group must carry a "conditions" list.');
            $conditions = [];
        }

        foreach (array_keys($node) as $key) {
            if (! in_array($key, ['boolean', 'conditions'], true)) {
                $errors->add($path.'.'.$key, sprintf('Unknown filter group key "%s".', $key));
            }
        }

        $parsed = [];

        foreach ($conditions as $index => $child) {
            $childPath = $path.'.conditions.'.$index;

            if (is_array($child) && array_key_exists('conditions', $child)) {
                $parsed[] = self::parseGroup($child, $childPath, $errors);
            } else {
                $parsed[] = self::parseLeaf($child, $childPath, $errors);
            }
        }

        /** @var 'and'|'or' $boolean */
        return new FilterGroup($boolean, $parsed);
    }

    private static function parseLeaf(mixed $node, string $path, Errors $errors): FilterCondition
    {
        if (! is_array($node)) {
            $errors->add($path, 'A filter condition must be an object with "filter" and "operator".');

            return new FilterCondition('', Operator::Equals);
        }

        foreach (array_keys($node) as $key) {
            if (! in_array($key, ['filter', 'operator', 'value'], true)) {
                $errors->add($path.'.'.$key, sprintf('Unknown filter condition key "%s".', $key));
            }
        }

        $filter = $node['filter'] ?? null;

        if (! is_string($filter) || $filter === '') {
            $errors->add($path.'.filter', 'A filter condition must name a filter.');
            $filter = '';
        }

        $operator = is_string($node['operator'] ?? null) ? Operator::tryFrom($node['operator']) : null;

        if ($operator === null) {
            $errors->add($path.'.operator', sprintf(
                'Unknown operator %s.',
                is_string($node['operator'] ?? null) ? '"'.$node['operator'].'"' : '',
            ));
            $operator = Operator::Equals;
        }

        return new FilterCondition($filter, $operator, $node['value'] ?? null, array_key_exists('value', $node));
    }

    /**
     * @return list<SortSpec>
     */
    private static function parseSorts(mixed $sorts, Errors $errors): array
    {
        if (! is_array($sorts) || ! array_is_list($sorts)) {
            $errors->add('sorts', 'Sorts must be a list of { key, direction }.');

            return [];
        }

        $parsed = [];

        foreach ($sorts as $index => $sort) {
            $key = is_array($sort) ? ($sort['key'] ?? null) : null;
            $direction = is_array($sort) ? ($sort['direction'] ?? 'asc') : null;

            if (! is_string($key) || $key === '') {
                $errors->add('sorts.'.$index.'.key', 'A sort must name a sort key.');

                continue;
            }

            if (! in_array($direction, ['asc', 'desc'], true)) {
                $errors->add('sorts.'.$index.'.direction', 'A sort direction must be "asc" or "desc".');

                continue;
            }

            $parsed[] = new SortSpec($key, $direction);
        }

        return $parsed;
    }

    /**
     * @return list<GroupSpec>
     */
    private static function parseGroups(mixed $groups, Errors $errors): array
    {
        if (! is_array($groups) || ! array_is_list($groups)) {
            $errors->add('groupBy', 'groupBy must be a list of { key, grain? }.');

            return [];
        }

        $parsed = [];

        foreach ($groups as $index => $group) {
            $key = is_array($group) ? ($group['key'] ?? null) : null;
            $grain = is_array($group) ? ($group['grain'] ?? null) : null;

            if (! is_string($key) || $key === '') {
                $errors->add('groupBy.'.$index.'.key', 'A group must name a field key.');

                continue;
            }

            if ($grain !== null && ! is_string($grain)) {
                $errors->add('groupBy.'.$index.'.grain', 'A grain must be a string.');

                continue;
            }

            $parsed[] = new GroupSpec($key, $grain);
        }

        return $parsed;
    }

    /**
     * @return list<AggregateSpec>
     */
    private static function parseAggregates(mixed $aggregates, Errors $errors): array
    {
        if (! is_array($aggregates) || ! array_is_list($aggregates)) {
            $errors->add('aggregates', 'Aggregates must be a list of { fn, field? }.');

            return [];
        }

        $parsed = [];

        foreach ($aggregates as $index => $aggregate) {
            $fn = is_array($aggregate) ? ($aggregate['fn'] ?? null) : null;
            $field = is_array($aggregate) ? ($aggregate['field'] ?? null) : null;

            if (! is_string($fn) || AggregateType::tryFrom($fn) === null) {
                $errors->add('aggregates.'.$index.'.fn', sprintf(
                    'Unknown aggregate function; expected one of %s.',
                    implode(', ', array_map(static fn (AggregateType $type): string => $type->value, AggregateType::cases())),
                ));

                continue;
            }

            if ($field !== null && ! is_string($field)) {
                $errors->add('aggregates.'.$index.'.field', 'An aggregate field must be a field key.');

                continue;
            }

            $parsed[] = new AggregateSpec($fn, $field);
        }

        return $parsed;
    }

    /**
     * @return list<string>|null
     */
    private static function parseSelect(mixed $select, Errors $errors): ?array
    {
        if ($select === null) {
            return null;
        }

        if (! is_array($select) || ! array_is_list($select)) {
            $errors->add('select', 'Select must be a list of field keys.');

            return null;
        }

        $keys = [];

        foreach ($select as $index => $key) {
            if (! is_string($key) || $key === '') {
                $errors->add('select.'.$index, 'A selected field must be a field key.');

                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    private static function parsePage(mixed $page, Errors $errors): PageSpec
    {
        if (! is_array($page)) {
            $errors->add('page', 'Page must be an object.');

            return new PageSpec;
        }

        foreach (array_keys($page) as $key) {
            if (! in_array($key, ['mode', 'size', 'after', 'number', 'withTotal'], true)) {
                $errors->add('page.'.$key, sprintf('Unknown page key "%s".', $key));
            }
        }

        $mode = $page['mode'] ?? (array_key_exists('number', $page) ? 'offset' : 'cursor');

        if (! in_array($mode, ['cursor', 'offset'], true)) {
            $errors->add('page.mode', 'Page mode must be "cursor" or "offset".');
            $mode = 'cursor';
        }

        $size = $page['size'] ?? null;

        if ($size !== null && (! is_int($size) || $size < 1)) {
            $errors->add('page.size', 'Page size must be a positive integer.');
            $size = null;
        }

        $after = $page['after'] ?? null;

        if ($after !== null && ! is_string($after)) {
            $errors->add('page.after', 'A cursor must be a string.');
            $after = null;
        }

        $number = $page['number'] ?? 1;

        if (! is_int($number) || $number < 1) {
            $errors->add('page.number', 'Page number must be a positive integer.');
            $number = 1;
        }

        $withTotal = $page['withTotal'] ?? true;

        if (! is_bool($withTotal)) {
            $errors->add('page.withTotal', 'withTotal must be a boolean.');
            $withTotal = true;
        }

        /** @var 'cursor'|'offset' $mode */
        return new PageSpec($mode, $size, $after, $number, $withTotal);
    }

    public function isAggregate(): bool
    {
        return $this->groupBy !== [] || $this->aggregates !== [];
    }

    /**
     * The canonical wire form — what the applied echo is built from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['source' => $this->source, 'parameters' => (object) $this->parameters];

        if ($this->search !== null) {
            $data['search'] = $this->search;
        }

        if (! $this->filters->isEmpty()) {
            $data['filters'] = $this->filters->toArray();
        }

        if ($this->sorts !== []) {
            $data['sorts'] = array_map(static fn (SortSpec $sort): array => $sort->toArray(), $this->sorts);
        }

        if ($this->groupBy !== []) {
            $data['groupBy'] = array_map(static fn (GroupSpec $group): array => $group->toArray(), $this->groupBy);
        }

        if ($this->aggregates !== []) {
            $data['aggregates'] = array_map(static fn (AggregateSpec $aggregate): array => $aggregate->toArray(), $this->aggregates);
        }

        if ($this->select !== null) {
            $data['select'] = $this->select;
        }

        $data['page'] = $this->page->toArray();

        return $data;
    }
}
