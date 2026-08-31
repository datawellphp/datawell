<?php

declare(strict_types=1);

use Datawell\DatawellServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges the package config', function (): void {
    expect(config('datawell.lint.warnings'))->toBe('log')
        ->and(config('datawell.timezone'))->toBeNull();
});

it('registers the config and migrations for publishing under the package tags', function (): void {
    $config = ServiceProvider::pathsToPublish(DatawellServiceProvider::class, 'datawell-config');

    expect(array_values($config))->toBe([config_path('datawell.php')])
        ->and(array_key_first($config))->toEndWith('config/datawell.php');

    $migrations = ServiceProvider::pathsToPublish(DatawellServiceProvider::class, 'datawell-migrations');

    expect(array_values($migrations))->toBe([database_path('migrations')])
        ->and(array_key_first($migrations))->toEndWith('database/migrations');

    // The umbrella tag carries both.
    expect(array_values(ServiceProvider::pathsToPublish(DatawellServiceProvider::class, 'datawell')))
        ->toBe([config_path('datawell.php'), database_path('migrations')]);
});
