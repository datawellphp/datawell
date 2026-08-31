<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Datawell\Query\QueryRequest;
use Datawell\Validation\ValidationException;

/**
 * The wire shape of an action invocation (D40):
 * `{ action, source, parameters, target: {ids: [...]} | {query: {...}, except: [...]}, input, approval }`.
 * Strict like QueryRequest: unknown top-level keys are rejected, shapes are checked here,
 * meaning (does the action accept this target, is the approval sufficient) is the executor's.
 */
final class ActionRequest
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $source,
        public readonly string $action,
        public readonly array $parameters = [],
        public readonly ?Target $target = null,
        public readonly array $input = [],
        public readonly ?string $approval = null,
    ) {}

    /**
     * @param  array<string, mixed>  $wire
     */
    public static function fromArray(array $wire): self
    {
        $known = ['source', 'action', 'parameters', 'target', 'input', 'approval'];

        foreach (array_keys($wire) as $key) {
            if (! in_array($key, $known, true)) {
                throw ValidationException::withErrors([(string) $key => [sprintf('Unknown key "%s".', $key)]]);
            }
        }

        $source = $wire['source'] ?? null;
        $action = $wire['action'] ?? null;

        if (! is_string($source) || $source === '') {
            throw ValidationException::withErrors(['source' => ['A source key is required.']]);
        }

        if (! is_string($action) || $action === '') {
            throw ValidationException::withErrors(['action' => ['An action key is required.']]);
        }

        foreach (['parameters', 'input'] as $key) {
            if (isset($wire[$key]) && ! is_array($wire[$key])) {
                throw ValidationException::withErrors([$key => [sprintf('"%s" must be an object.', $key)]]);
            }
        }

        if (isset($wire['approval']) && ! is_string($wire['approval'])) {
            throw ValidationException::withErrors(['approval' => ['"approval" must be a string reference.']]);
        }

        return new self(
            $source,
            $action,
            $wire['parameters'] ?? [],
            isset($wire['target']) ? self::target($wire['target'], $source, $wire['parameters'] ?? []) : null,
            $wire['input'] ?? [],
            $wire['approval'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function target(mixed $target, string $source, array $parameters): Target
    {
        if (! is_array($target)) {
            throw ValidationException::withErrors(['target' => ['"target" must be {ids: [...]} or {query: {...}, except: [...]}.']]);
        }

        if (array_key_exists('ids', $target)) {
            foreach (array_keys($target) as $key) {
                if ($key !== 'ids') {
                    throw ValidationException::withErrors(['target' => [sprintf('An ids target accepts only "ids"; "%s" is unknown.', (string) $key)]]);
                }
            }

            return Target::ids(self::idList($target['ids'], 'target.ids', allowEmpty: false));
        }

        if (array_key_exists('query', $target)) {
            foreach (array_keys($target) as $key) {
                if (! in_array($key, ['query', 'except'], true)) {
                    throw ValidationException::withErrors(['target' => [sprintf('A query target accepts "query" and "except"; "%s" is unknown.', (string) $key)]]);
                }
            }

            if (! is_array($target['query'])) {
                throw ValidationException::withErrors(['target.query' => ['"query" must be a query request object.']]);
            }

            $query = $target['query'];

            if (isset($query['source']) && $query['source'] !== $source) {
                throw ValidationException::withErrors(['target.query.source' => ['The target query must address the action\'s own source.']]);
            }

            // The scope is "all rows matching this view of the action's source": the
            // outer parameters carry over, and only the read grammar's filtering side
            // is meaningful here.
            foreach (['sorts', 'select', 'page', 'groupBy', 'aggregates'] as $key) {
                if (isset($query[$key])) {
                    throw ValidationException::withErrors(['target.query.'.$key => [sprintf('A target query does not accept "%s"; it only selects rows.', $key)]]);
                }
            }

            return Target::scope(
                QueryRequest::fromArray(['source' => $source, 'parameters' => $parameters] + array_intersect_key($query, array_flip(['filters', 'search']))),
                self::idList($target['except'] ?? [], 'target.except', allowEmpty: true),
            );
        }

        throw ValidationException::withErrors(['target' => ['"target" must be {ids: [...]} or {query: {...}, except: [...]}.']]);
    }

    /**
     * @return list<int|string>
     */
    private static function idList(mixed $ids, string $path, bool $allowEmpty): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || (! $allowEmpty && $ids === [])) {
            throw ValidationException::withErrors([$path => ['Expected a non-empty list of ids.']]);
        }

        foreach ($ids as $id) {
            if (! is_int($id) && ! is_string($id)) {
                throw ValidationException::withErrors([$path => ['Ids must be integers or strings.']]);
            }
        }

        /** @var list<int|string> $ids */
        return array_values(array_unique($ids));
    }
}
