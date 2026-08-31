<?php

declare(strict_types=1);

use Datawell\Attributes\Model;
use Datawell\Fields\RelationField;
use Datawell\Registry;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Sources\Tags;

it('passes for the fixture sources', function (): void {
    $this->artisan('datawell:lint')
        ->expectsOutputToContain('4 data source(s) linted, 0 warning(s).')
        ->assertSuccessful();
});

it('fails on errors', function (): void {
    app(Registry::class)->register(new #[Model(Signature::class)] class extends Tags
    {
        public function key(): string
        {
            return 'broken';
        }

        public function fields(): array
        {
            return [RelationField::make('tags', from: 'tags')->sortable()];
        }
    });

    $this->artisan('datawell:lint')
        ->expectsOutputToContain('tags is a to-many path and cannot be sortable')
        ->assertFailed();
});

it('fails on warnings only with --strict', function (): void {
    app(Registry::class)->register(new class extends Tags
    {
        public function key(): string
        {
            return 'undescribed';
        }

        public function description(): string
        {
            return '';
        }
    });

    $this->artisan('datawell:lint')->expectsOutputToContain('1 warning(s)')->assertSuccessful();
    $this->artisan('datawell:lint', ['--strict' => true])->assertFailed();
});
