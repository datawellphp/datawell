<?php

declare(strict_types=1);

use Datawell\Execution\Channel;
use Datawell\Executor;
use Datawell\Validation\ValidationException;

beforeEach(function (): void {
    $this->seedDatabase();
});

function signatures(array $wire, $user = null, Channel $channel = Channel::Direct): array
{
    return app(Executor::class)->run(
        ['source' => 'document-signatures', 'parameters' => ['document_id' => 123], ...$wire],
        $user ?? test()->viewer(),
        $channel,
    )->toArray();
}

function ids(array $result): array
{
    return array_column($result['rows'], 'id');
}

it('renders the worked-example row: relation refs, many-values with a total, hidden relation-backed scalar absent', function (): void {
    $result = signatures(['page' => ['size' => 10]]);

    expect(ids($result))->toBe([6, 5, 4, 3, 2, 1]);

    $first = collect($result['rows'])->firstWhere('id', 1);

    expect($first)->toBe([
        'id' => 1,
        'url' => '/documents/123/signatures/1',
        'actions' => ['send_reminder' => true, 'void_signature' => true, 'edit' => '/documents/123/signatures/1/edit', 'preview' => ['signature_id' => 1]],
        'signer' => ['id' => 1, 'label' => 'Anna Smith', 'url' => '/people/1'],
        'status' => ['id' => 'signed', 'label' => 'Signed'],
        'requested_at' => '2026-08-10T09:00:00Z',
        'signed_at' => '2026-08-11T10:00:00Z',
        'tags' => ['items' => [['id' => 3, 'label' => 'Legal'], ['id' => 14, 'label' => 'Urgent']], 'total' => 2],
        'reminders_count' => 2,
        'last_reminder_at' => '2026-08-22T09:00:00Z',
    ]);
});

it('renders a relation-backed scalar for a user who may see it', function (): void {
    $result = signatures(['page' => ['size' => 10]], test()->privilegedViewer());
    $first = collect($result['rows'])->firstWhere('id', 1);

    expect($first['signer_email'])->toBe('anna@acme.com')
        ->and(collect($result['rows'])->firstWhere('id', 6)['signer_email'])->toBeNull();
});

it('renders a missing to-one relation as null and an empty to-many as no items', function (): void {
    $row = collect(signatures(['page' => ['size' => 10]])['rows'])->firstWhere('id', 6);

    expect($row['signer'])->toBeNull()
        ->and($row['tags'])->toBe(['items' => [['id' => 9, 'label' => 'Internal']], 'total' => 1]);

    $untagged = collect(signatures(['page' => ['size' => 10]])['rows'])->firstWhere('id', 3);

    expect($untagged['tags'])->toBe(['items' => [], 'total' => 0]);
});

it('traverses relations without re-scoping the target source (D36)', function (): void {
    $row = collect(signatures(['page' => ['size' => 10]])['rows'])->firstWhere('id', 5);

    // Zed is outside the people source's workspace scope: the label still renders on the
    // signature, while the reference does not resolve through the people pipeline.
    expect($row['signer'])->toBe(['id' => 11, 'label' => 'Zed Outsider', 'url' => '/people/11'])
        ->and(app(Executor::class)->lookup('people', 11, test()->viewer()))->toBeNull();
});

it('caps many-values per row and reports the total', function (): void {
    config()->set('datawell.values.max', 2);

    $row = collect(signatures(['page' => ['size' => 10]])['rows'])->firstWhere('id', 4);

    expect($row['tags'])->toBe(['items' => [['id' => 3, 'label' => 'Legal'], ['id' => 9, 'label' => 'Internal']], 'total' => 3]);
});

it('selects relation fields like any other', function (): void {
    $result = signatures(['select' => ['signer'], 'page' => ['size' => 1]]);

    expect(array_keys($result['rows'][0]))->toBe(['id', 'url', 'actions', 'signer']);
});

it('resolves the representation through a relation for lookups', function (): void {
    $ref = app(Executor::class)->lookup('document-signatures', 1, test()->viewer(), ['document_id' => 123]);

    expect($ref?->toArray())->toBe(['id' => 1, 'label' => 'Anna Smith', 'url' => '/documents/123/signatures/1']);
});

function filtered(array $conditions, $user = null): array
{
    return ids(signatures(['filters' => ['conditions' => $conditions], 'page' => ['size' => 20]], $user));
}

it('filters a to-one relation field by id: in as a semi-join, notIn as its anti-join', function (): void {
    expect(filtered([['filter' => 'signer', 'operator' => 'in', 'value' => [1, 3]]]))->toBe([4, 3, 1])
        // notIn keeps the row with no signer at all (D54).
        ->and(filtered([['filter' => 'signer', 'operator' => 'notIn', 'value' => [1]]]))->toBe([6, 5, 3, 2]);
});

it('filters a to-many relation field with the documented tag combinations (examples §6)', function (): void {
    expect(filtered([['filter' => 'tags', 'operator' => 'hasAny', 'value' => [14, 9]]]))->toBe([6, 4, 2, 1])
        ->and(filtered([['filter' => 'tags', 'operator' => 'hasAll', 'value' => [14, 3]]]))->toBe([4, 1])
        ->and(filtered([['filter' => 'tags', 'operator' => 'hasNone', 'value' => [9]]]))->toBe([5, 3, 2, 1])
        ->and(filtered([['filter' => 'tags', 'operator' => 'isEmpty']]))->toBe([5, 3])
        ->and(filtered([['filter' => 'tags', 'operator' => 'isNotEmpty']]))->toBe([6, 4, 2, 1])
        ->and(filtered([
            ['filter' => 'tags', 'operator' => 'hasAll', 'value' => [14, 3]],
            ['filter' => 'tags', 'operator' => 'hasNone', 'value' => [9]],
        ]))->toBe([1]);
});

it('never duplicates a parent row however many related rows match', function (): void {
    $result = signatures(['filters' => ['conditions' => [['filter' => 'tags', 'operator' => 'hasAny', 'value' => [3, 9, 14]]]], 'page' => ['number' => 1, 'size' => 20]]);

    expect(ids($result))->toBe([6, 4, 2, 1])->and($result['meta']['total'])->toBe(4);
});

it('filters a relation-backed scalar through the relation', function (): void {
    $user = test()->privilegedViewer();

    expect(filtered([['filter' => 'signer_email', 'operator' => 'endsWith', 'value' => '@acme.com']], $user))->toBe([4, 2, 1])
        ->and(filtered([['filter' => 'signer_email', 'operator' => 'equals', 'value' => 'cara@rival.com']], $user))->toBe([3])
        // isEmpty on a to-one path: no signer, or a signer without the value.
        ->and(filtered([['filter' => 'signer_email', 'operator' => 'isEmpty']], $user))->toBe([6])
        ->and(filtered([['filter' => 'signer_email', 'operator' => 'isNotEmpty']], $user))->toBe([5, 4, 3, 2, 1]);
});

it('combines relation filters inside boolean groups', function (): void {
    $result = signatures(['filters' => ['boolean' => 'or', 'conditions' => [
        ['filter' => 'signer', 'operator' => 'in', 'value' => [3]],
        ['boolean' => 'and', 'conditions' => [
            ['filter' => 'tags', 'operator' => 'hasAny', 'value' => [9]],
            ['filter' => 'status', 'operator' => 'in', 'value' => ['pending']],
        ]],
    ]], 'page' => ['size' => 20]]);

    expect(ids($result))->toBe([6, 4, 3]);
});

it('searches through a relation field via the target label and through relation-backed text', function (): void {
    expect(ids(signatures(['search' => 'smith', 'page' => ['size' => 20]])))->toBe([4, 3, 1])
        ->and(ids(signatures(['search' => 'anna smith', 'page' => ['size' => 20]])))->toBe([4, 1])
        // The hidden signer_email is not searched for the viewer…
        ->and(ids(signatures(['search' => 'rival', 'page' => ['size' => 20]])))->toBe([])
        // …but it is for a user who may see it.
        ->and(ids(signatures(['search' => 'rival', 'page' => ['size' => 20]], test()->privilegedViewer())))->toBe([3]);
});

it('rejects filters on hidden relation-backed fields as unknown', function (): void {
    expect(fn () => signatures(['filters' => ['conditions' => [['filter' => 'signer_email', 'operator' => 'contains', 'value' => '@rival.com']]]]))
        ->toThrow(ValidationException::class, 'Unknown filter "signer_email".');
});

function sorted(array $sorts, array $page = ['size' => 20], $user = null): array
{
    return signatures(['sorts' => $sorts, 'page' => $page], $user);
}

it('sorts by a relation field through its target label, nulls last either way', function (): void {
    // Labels: 1 Anna, 4 Anna, 2 Ben, 3 Cara, 5 Zed, 6 (no signer)
    expect(ids(sorted([['key' => 'signer', 'direction' => 'asc']])))->toBe([1, 4, 2, 3, 5, 6])
        ->and(ids(sorted([['key' => 'signer', 'direction' => 'desc']])))->toBe([5, 3, 2, 1, 4, 6]);
});

it('sorts by a relation-backed scalar and keeps a row without the relation last', function (): void {
    // Emails: anna(1,4) ben(2) cara(3) zed(5), none(6)
    expect(ids(sorted([['key' => 'signer_email', 'direction' => 'desc']], user: test()->privilegedViewer())))->toBe([5, 3, 2, 1, 4, 6]);
});

it('pages a relation sort by cursor without skipping or repeating rows', function (): void {
    $walk = [];
    $after = null;

    do {
        $page = sorted([['key' => 'signer', 'direction' => 'asc']], ['after' => $after, 'size' => 2]);
        $walk = [...$walk, ...ids($page)];
        $after = $page['meta']['nextCursor'];
    } while ($page['meta']['hasMore']);

    expect($walk)->toBe([1, 4, 2, 3, 5, 6]);
});

it('pages a relation sort by offset with a total', function (): void {
    $page = sorted([['key' => 'signer', 'direction' => 'asc']], ['number' => 2, 'size' => 4]);

    expect(ids($page))->toBe([5, 6])->and($page['meta']['total'])->toBe(6);
});

it('combines a relation sort with relation filters and search on one query', function (): void {
    $result = signatures([
        'search' => 'smith',
        'filters' => ['conditions' => [['filter' => 'tags', 'operator' => 'hasAny', 'value' => [3, 14]]]],
        'sorts' => [['key' => 'signer', 'direction' => 'desc'], ['key' => 'requested_at', 'direction' => 'asc']],
        'page' => ['size' => 20],
    ]);

    expect(ids($result))->toBe([1, 4]);
});

it('groups by a to-one relation into reference buckets (examples §7)', function (): void {
    $result = app(Executor::class)->run([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'groupBy' => [['key' => 'signer']],
        'aggregates' => [['fn' => 'count']],
    ], test()->viewer())->toArray();

    expect($result['buckets'])->toBe([
        ['signer' => ['id' => 1, 'label' => 'Anna Smith'], 'count' => 2],
        ['signer' => ['id' => 2, 'label' => 'Ben Okoro'], 'count' => 1],
        ['signer' => ['id' => 3, 'label' => 'Cara Smith'], 'count' => 1],
        ['signer' => ['id' => 11, 'label' => 'Zed Outsider'], 'count' => 1],
        ['signer' => null, 'count' => 1],
    ])->and($result['meta'])->toBe(['count' => 5, 'truncated' => false]);
});

it('groups by a relation with a filter on the same relation without duplicating', function (): void {
    $result = app(Executor::class)->run([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'filters' => ['conditions' => [['filter' => 'tags', 'operator' => 'hasAny', 'value' => [3, 14]]]],
        'groupBy' => [['key' => 'signer']],
        'aggregates' => [['fn' => 'count']],
    ], test()->viewer())->toArray();

    expect($result['buckets'])->toBe([
        ['signer' => ['id' => 1, 'label' => 'Anna Smith'], 'count' => 2],
        ['signer' => ['id' => 2, 'label' => 'Ben Okoro'], 'count' => 1],
    ]);
});

it('rejects sorting or grouping by a hidden relation-backed field as unknown', function (): void {
    expect(fn () => sorted([['key' => 'signer_email', 'direction' => 'asc']]))
        ->toThrow(ValidationException::class, 'Unknown sort "signer_email".');
});
