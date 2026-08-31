<?php

declare(strict_types=1);

use Datawell\Execution\Channel;
use Datawell\Executor;

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
        'reminders_count' => null,
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
