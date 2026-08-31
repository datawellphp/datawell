<?php

declare(strict_types=1);

use Datawell\Exceptions\DefinitionException;
use Datawell\Exceptions\SourceNotFoundException;
use Datawell\Registry;
use Datawell\Tests\Fixtures\Sources\Documents;
use Datawell\Tests\Fixtures\Sources\DocumentSignatures;
use Datawell\Tests\Fixtures\Sources\Tags;

it('registers the configured sources at boot', function (): void {
    $registry = app(Registry::class);

    expect(array_map(fn ($source) => $source->key(), $registry->all()))
        ->toBe(['document-signatures', 'documents', 'tags'])
        ->and($registry->has('tags'))->toBeTrue()
        ->and($registry->find('documents'))->toBeInstanceOf(Documents::class);
});

it('resolves class references to keys and never the reverse', function (): void {
    $registry = app(Registry::class);

    expect($registry->keyOf(DocumentSignatures::class))->toBe('document-signatures')
        ->and(fn () => $registry->keyOf(self::class))->toThrow(DefinitionException::class);
});

it('throws not-found for unknown keys', function (): void {
    expect(fn () => app(Registry::class)->find('nope'))->toThrow(SourceNotFoundException::class);
});

it('rejects two classes claiming one key', function (): void {
    $impostor = new class extends Tags {};

    expect(fn () => app(Registry::class)->register($impostor))
        ->toThrow(DefinitionException::class, 'Data source key "tags" is declared by both');
});

it('is idempotent for the same class', function (): void {
    $registry = app(Registry::class)->register(Tags::class);

    expect(count($registry->all()))->toBe(3);
});
