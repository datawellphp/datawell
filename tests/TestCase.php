<?php

declare(strict_types=1);

namespace Datawell\Tests;

use Datawell\DatawellServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DatawellServiceProvider::class,
        ];
    }
}
