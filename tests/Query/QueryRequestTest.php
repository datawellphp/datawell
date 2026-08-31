<?php

declare(strict_types=1);

use Datawell\Query\QueryRequest;
use Datawell\Validation\ValidationException;

it('round-trips the worked example request', function (): void {
    $wire = [
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'search' => 'smith',
        'filters' => [
            'boolean' => 'or',
            'conditions' => [
                ['filter' => 'status', 'operator' => 'in', 'value' => ['pending']],
                ['boolean' => 'and', 'conditions' => [
                    ['filter' => 'signed_at', 'operator' => 'isEmpty'],
                    ['filter' => 'requested_at', 'operator' => 'after', 'value' => ['relative' => 'ago', 'n' => 30, 'unit' => 'days']],
                ]],
            ],
        ],
        'sorts' => [['key' => 'requested_at', 'direction' => 'desc']],
        'page' => ['mode' => 'offset', 'size' => 25, 'number' => 1, 'withTotal' => true],
    ];

    $request = QueryRequest::fromArray($wire);

    expect($request->filters->depth())->toBe(2)
        ->and(count($request->filters->leaves()))->toBe(3)
        ->and($request->page->isCursor())->toBeFalse()
        ->and(json_decode(json_encode($request->toArray()), true))->toBe($wire);
});

it('defaults the root boolean to and, and the page to cursor mode', function (): void {
    $request = QueryRequest::fromArray(['source' => 'tags', 'filters' => ['conditions' => []]]);

    expect($request->filters->boolean)->toBe('and')
        ->and($request->page->toArray())->toBe(['mode' => 'cursor'])
        ->and($request->toArray()['page'])->toBe(['mode' => 'cursor']);
});

it('infers offset mode from a page number', function (): void {
    expect(QueryRequest::fromArray(['source' => 'tags', 'page' => ['number' => 3, 'size' => 10]])->page->toArray())
        ->toBe(['mode' => 'offset', 'size' => 10, 'number' => 3, 'withTotal' => true]);
});

it('rejects malformed shapes with errors keyed by path', function (array $wire, string $path, string $message): void {
    try {
        QueryRequest::fromArray(['source' => 'tags', ...$wire]);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($path)
            ->and($exception->errors()[$path][0])->toBe($message);

        return;
    }

    $this->fail('Expected a ValidationException.');
})->with([
    'unknown key' => [['perPage' => 25], 'perPage', 'Unknown request key "perPage".'],
    'unknown operator' => [['filters' => ['conditions' => [['filter' => 'x', 'operator' => 'like', 'value' => 1]]]], 'filters.conditions.0.operator', 'Unknown operator "like".'],
    'leaf without filter' => [['filters' => ['conditions' => [['operator' => 'equals']]]], 'filters.conditions.0.filter', 'A filter condition must name a filter.'],
    'group without conditions' => [['filters' => ['boolean' => 'or']], 'filters.conditions', 'A filter group must carry a "conditions" list.'],
    'bad boolean' => [['filters' => ['boolean' => 'xor', 'conditions' => []]], 'filters.boolean', 'A filter group\'s "boolean" must be "and" or "or".'],
    'bad direction' => [['sorts' => [['key' => 'a', 'direction' => 'up']]], 'sorts.0.direction', 'A sort direction must be "asc" or "desc".'],
    'bad aggregate' => [['aggregates' => [['fn' => 'median']]], 'aggregates.0.fn', 'Unknown aggregate function; expected one of count, sum, avg, min, max.'],
    'bad page size' => [['page' => ['size' => 0]], 'page.size', 'Page size must be a positive integer.'],
    'bad page mode' => [['page' => ['mode' => 'scroll']], 'page.mode', 'Page mode must be "cursor" or "offset".'],
    'missing source' => [['source' => ''], 'source', 'A source key is required.'],
]);

it('exposes the first error as the exception message', function (): void {
    expect(fn () => QueryRequest::fromArray(['source' => 'tags', 'nope' => 1]))
        ->toThrow(ValidationException::class, 'Unknown request key "nope".');
});
