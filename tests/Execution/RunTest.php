<?php

declare(strict_types=1);

use Datawell\Execution\Channel;
use Datawell\Executor;
use Datawell\Validation\ValidationException;

beforeEach(function (): void {
    $this->seedDatabase();
});

function run(array $wire, $user = null, Channel $channel = Channel::Direct): array
{
    return app(Executor::class)->run(['source' => 'people', ...$wire], $user ?? test()->viewer(), $channel)->toArray();
}

function names(array $result): array
{
    return array_column($result['rows'], 'name');
}

it('runs the resting request: scoped rows, defaulted filter, default sort, self-links, refs, actions', function (): void {
    $result = run(['page' => ['number' => 1, 'size' => 5]]);

    expect(names($result))->toBe(['Anna Smith', 'Ben Okoro', 'Cara Smith', 'Eli Brown', 'Fay Chen'])
        ->and($result['meta'])->toBe(['mode' => 'offset', 'size' => 5, 'hasMore' => true, 'number' => 1, 'total' => 8])
        ->and($result['applied']['filters'])->toBe(['boolean' => 'and', 'conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => true]]])
        ->and($result['applied']['sorts'])->toBe([['key' => 'name', 'direction' => 'asc']]);

    $anna = $result['rows'][0];
    expect($anna)->toBe([
        'id' => 1,
        'url' => '/people/1',
        'actions' => ['edit' => '/people/1/edit', 'purge' => true],
        'name' => 'Anna Smith',
        'role' => ['id' => 'admin', 'label' => 'Admin'],
        'age' => 41,
        'active' => true,
        'notes' => 'Founder',
        'joined_on' => '2026-08-18',
        'last_seen_at' => '2026-08-18T03:30:00Z',
    ]);

    $ben = $result['rows'][1];
    expect($ben['actions'])->toBe(['edit' => '/people/2/edit', 'deactivate' => true, 'purge' => true])
        ->and(array_keys($ben))->not->toContain('email');
});

it('never returns rows outside the source scope', function (): void {
    $result = run(['filters' => ['conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => true]]], 'page' => ['size' => 50]]);

    expect(names($result))->not->toContain('Zed Outsider')->and(count($result['rows']))->toBe(8);
});

it('overrides a defaulted filter with an explicit condition', function (): void {
    $result = run(['filters' => ['conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => false]]]]);

    expect(names($result))->toBe(['Dev Patel', 'Gus Reyes'])
        ->and($result['applied']['filters']['conditions'])->toHaveCount(1);
});

it('compiles nested boolean groups', function (): void {
    $result = run(['filters' => [
        'boolean' => 'or',
        'conditions' => [
            ['filter' => 'role', 'operator' => 'in', 'value' => ['admin']],
            ['boolean' => 'and', 'conditions' => [
                ['filter' => 'age', 'operator' => 'between', 'value' => ['from' => 30, 'to' => 40]],
                ['filter' => 'name', 'operator' => 'endsWith', 'value' => 'n'],
            ]],
        ],
    ]]);

    expect(names($result))->toBe(['Anna Smith', 'Eli Brown', 'Fay Chen', 'Jon Alder']);
});

it('handles null operators, escaped wildcards and custom filters', function (): void {
    expect(names(run(['filters' => ['conditions' => [['filter' => 'age', 'operator' => 'isEmpty']]]], channel: Channel::Direct)))->toBe([])
        ->and(names(run(['filters' => ['conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => false], ['filter' => 'age', 'operator' => 'isEmpty']]]])))->toBe(['Dev Patel'])
        ->and(names(run(['filters' => ['conditions' => [['filter' => 'notes', 'operator' => 'contains', 'value' => '50%']]]])))->toBe(['Cara Smith'])
        ->and(names(run(['filters' => ['conditions' => [['filter' => 'notes', 'operator' => 'contains', 'value' => '_score']]]])))->toBe(['Eli Brown'])
        ->and(names(run(['filters' => ['conditions' => [['filter' => 'notes', 'operator' => 'contains', 'value' => 'o']]]])))->toBe(['Anna Smith', 'Eli Brown'])
        ->and(names(run(['filters' => ['conditions' => [['filter' => 'adults_only', 'operator' => 'is', 'value' => false]]]])))->toBe(['Cara Smith']);
});

it('searches across searchable visible text fields: OR fields, AND terms', function (): void {
    expect(names(run(['search' => 'smith'])))->toBe(['Anna Smith', 'Cara Smith', 'Ivy Smithson'])
        ->and(names(run(['search' => 'smith  an'])))->toBe(['Anna Smith'])
        ->and(names(run(['search' => 'acme.com'])))->toBe([])
        ->and(names(run(['search' => 'acme.com'], test()->privilegedViewer())))->toHaveCount(7);
});

it('paginates by offset with the primary key as a tie-breaker', function (): void {
    $wire = ['sorts' => [['key' => 'age', 'direction' => 'desc']], 'page' => ['size' => 3, 'number' => 2, 'withTotal' => false]];
    $page2 = run($wire);

    // Active people by age desc: Jon 60, Anna 41, Eli/Fay/Hana 35 (ids 5, 6, 8), Ben 29, Ivy 23, Cara 17.
    expect(names($page2))->toBe(['Fay Chen', 'Hana Ito', 'Ben Okoro'])
        ->and($page2['meta'])->toBe(['mode' => 'offset', 'size' => 3, 'hasMore' => true, 'number' => 2]);
});

it('walks a cursor without repeating or skipping under a non-unique sort', function (): void {
    $seen = [];
    $after = null;

    do {
        $page = run(['sorts' => [['key' => 'age', 'direction' => 'desc']], 'page' => ['size' => 3, 'after' => $after]]);
        array_push($seen, ...names($page));
        $after = $page['meta']['nextCursor'];
        expect($page['meta']['mode'])->toBe('cursor')->and(array_key_exists('total', $page['meta']))->toBeFalse();
    } while ($page['meta']['hasMore']);

    expect($seen)->toBe(['Jon Alder', 'Anna Smith', 'Eli Brown', 'Fay Chen', 'Hana Ito', 'Ben Okoro', 'Ivy Smithson', 'Cara Smith'])
        ->and($after)->toBeNull();
});

it('rejects a cursor that does not fit the request', function (): void {
    expect(fn () => run(['page' => ['after' => 'garbage']]))->toThrow(ValidationException::class, 'The cursor is invalid for this request.');
});

it('honours select and caps the page size at the default', function (): void {
    $result = run(['select' => ['name', 'role']]);

    expect(array_keys($result['rows'][0]))->toBe(['id', 'url', 'actions', 'name', 'role'])
        ->and($result['meta']['size'])->toBe(25);
});

it('drops humanOnly actions from the row map on delegated channels', function (): void {
    $direct = run(['page' => ['size' => 1]]);
    $delegated = run(['page' => ['size' => 1]], channel: Channel::DelegatedInteractive);

    expect($direct['rows'][0]['actions'])->toHaveKey('purge')
        ->and($delegated['rows'][0]['actions'])->not->toHaveKey('purge');
});

it('refuses to run an invalid request and runs nothing', function (): void {
    expect(fn () => run(['filters' => ['conditions' => [['filter' => 'email', 'operator' => 'contains', 'value' => 'x']]]]))
        ->toThrow(ValidationException::class, 'Unknown filter "email".');
});
