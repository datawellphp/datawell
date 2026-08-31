<?php

declare(strict_types=1);

use Datawell\DatawellServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges the package config', function (): void {
    expect(config('datawell.lint.warnings'))->toBe('log')
        ->and(config('datawell.timezone'))->toBeNull();
});

it('registers the config file for publishing under the package tags', function (): void {
    foreach (['datawell', 'datawell-config'] as $tag) {
        $paths = ServiceProvider::pathsToPublish(DatawellServiceProvider::class, $tag);

        expect(array_values($paths))->toBe([config_path('datawell.php')])
            ->and(array_key_first($paths))->toEndWith('config/datawell.php');
    }
});
