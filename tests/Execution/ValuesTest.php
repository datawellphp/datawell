<?php

declare(strict_types=1);

use Datawell\Executor;
use Datawell\Validation\ValidationException;

beforeEach(function (): void {
    $this->seedDatabase();
});

function values(int $id, array $page = [], string $field = 'tags', $user = null): ?array
{
    return app(Executor::class)->values('document-signatures', $id, $field, $user ?? test()->viewer(), ['document_id' => 123], $page)?->toArray();
}

it('pages the remainder of a many-valued field in target-key order', function (): void {
    $first = values(4, ['size' => 2]);

    expect($first['rows'])->toBe([['id' => 3, 'label' => 'Legal'], ['id' => 9, 'label' => 'Internal']])
        ->and($first['meta']['hasMore'])->toBeTrue();

    $second = values(4, ['after' => $first['meta']['nextCursor'], 'size' => 2]);

    expect($second['rows'])->toBe([['id' => 14, 'label' => 'Urgent']])
        ->and($second['meta']['hasMore'])->toBeFalse();
});

it('pages by offset with a total', function (): void {
    $page = values(4, ['number' => 2, 'size' => 2]);

    expect($page['rows'])->toBe([['id' => 14, 'label' => 'Urgent']])
        ->and($page['meta']['total'])->toBe(3);
});

it('returns null for an entity outside the caller\'s scope', function (): void {
    expect(values(7))->toBeNull();
});

it('rejects a field that is not a visible many-valued relation as unknown', function (): void {
    expect(fn () => values(4, field: 'signer'))->toThrow(ValidationException::class, 'Unknown field "signer".')
        ->and(fn () => values(4, field: 'nope'))->toThrow(ValidationException::class, 'Unknown field "nope".');
});

it('enforces the page ceiling', function (): void {
    expect(fn () => values(4, ['size' => 500]))->toThrow(ValidationException::class, 'Page size may not exceed 100.');
});
