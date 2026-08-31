<?php

declare(strict_types=1);

use Datawell\Actions\LinkAction;
use Datawell\Exceptions\SourceNotFoundException;
use Datawell\Registry;
use Datawell\Tests\Fixtures\Sources\Tags;

it('omits a hidden field, and its derived filter and sort, from the schema', function (): void {
    $registry = app(Registry::class);
    $source = $registry->find('document-signatures');

    $hidden = $source->describe($this->viewer())->toArray();
    $shown = $source->describe($this->privilegedViewer())->toArray();

    expect(array_column($hidden['fields'], 'key'))->not->toContain('signer_email')
        ->and(array_column($hidden['filters'], 'key'))->not->toContain('signer_email')
        ->and(array_column($hidden['sorts'], 'key'))->not->toContain('signer_email')
        ->and(json_encode($hidden))->not->toContain('signer_email')
        ->and(array_column($shown['fields'], 'key'))->toContain('signer_email');
});

it('makes a gated source indistinguishable from an unknown one', function (): void {
    $registry = app(Registry::class);

    expect(fn () => $registry->findFor('document-signatures', $this->outsider()))
        ->toThrow(SourceNotFoundException::class, 'Unknown data source "document-signatures".');
    expect(fn () => $registry->findFor('nope', $this->outsider()))
        ->toThrow(SourceNotFoundException::class, 'Unknown data source "nope".');
});

it('lists only the sources a user may know exist', function (): void {
    $registry = app(Registry::class);

    $keys = fn ($user) => array_map(fn ($source) => $source->key(), $registry->availableFor($user));

    expect($keys($this->viewer()))->toBe(['document-signatures', 'documents', 'tags', 'people'])
        ->and($keys($this->outsider()))->toBe(['documents', 'tags', 'people']);
});

it('omits an action hidden for the user', function (): void {
    $source = new class extends Tags
    {
        public function actions(): array
        {
            return [
                LinkAction::make('manage')
                    ->visibleWhen('manage-tags')
                    ->url(fn (): string => '/tags'),
            ];
        }
    };

    expect($source->describe($this->viewer())->toArray()['actions'])->toBe([]);
});
