<?php

declare(strict_types=1);

it('merges the package config', function () {
    expect(config('datawell.sources'))->toBe([]);
});

it('publishes the config file under the package tags', function () {
    $this->artisan('vendor:publish', ['--tag' => 'datawell-config', '--force' => true])
        ->assertSuccessful();

    expect(config_path('datawell.php'))->toBeFile();
});
