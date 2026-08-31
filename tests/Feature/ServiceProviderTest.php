<?php

declare(strict_types=1);

it('merges the package config', function (): void {
    expect(config('datawell.lint.warnings'))->toBe('log')
        ->and(config('datawell.timezone'))->toBeNull();
});

it('publishes the config file under the package tags', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'datawell-config', '--force' => true])
        ->assertSuccessful();

    expect(config_path('datawell.php'))->toBeFile();

    unlink(config_path('datawell.php'));
});
