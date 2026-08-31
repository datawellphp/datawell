<?php

declare(strict_types=1);

namespace Datawell;

use Illuminate\Support\ServiceProvider;

class DatawellServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/datawell.php', 'datawell');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/datawell.php' => config_path('datawell.php'),
        ], ['datawell', 'datawell-config']);
    }
}
