<?php

declare(strict_types=1);

namespace Datawell;

use Datawell\Console\LintCommand;
use Datawell\Console\MakeDatawellCommand;
use Datawell\Exceptions\DefinitionException;
use Datawell\Lint\DefinitionLinter;
use Datawell\Timezone\TimezoneResolver;
use Datawell\Validation\ProvenanceResolver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Reedware\LaravelRelationJoins\LaravelRelationJoinServiceProvider;

class DatawellServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/datawell.php', 'datawell');

        // The join package (D50) is used only inside Relations\RelationResolver; registering
        // it here means a host with package discovery disabled still gets the mixin.
        $this->app->register(LaravelRelationJoinServiceProvider::class);

        $this->app->singleton(Registry::class);
        $this->app->singleton(TimezoneResolver::class);
        $this->app->singleton(Executor::class);
        $this->app->singleton(ProvenanceResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->booted(function (Application $app): void {
            $this->registerConfiguredSources($app);
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/datawell.php' => config_path('datawell.php'),
        ], ['datawell', 'datawell-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['datawell', 'datawell-migrations']);

        $this->commands([
            MakeDatawellCommand::class,
            LintCommand::class,
        ]);
    }

    /**
     * Register the configured sources and, outside production, lint them so wrong
     * definitions fail loudly at boot (D20).
     *
     * @throws DefinitionException
     */
    protected function registerConfiguredSources(Application $app): void
    {
        $config = $app->make(Repository::class);
        $registry = $app->make(Registry::class);

        /** @var list<class-string<DataSource>> $sources */
        $sources = $config->get('datawell.sources', []);
        $registry->register(...$sources);

        $enabled = $config->get('datawell.lint.enabled');

        if ($enabled === null ? $app->isProduction() : ! $enabled) {
            return;
        }

        $report = $app->make(DefinitionLinter::class)->lint($registry);
        $report->throwIfErrors();

        $warnings = $config->get('datawell.lint.warnings', 'log');

        if ($warnings === 'throw' && $report->warnings !== []) {
            throw DefinitionException::fromProblems($report->warnings);
        }

        if ($warnings === 'log') {
            $logger = $app->make(LoggerInterface::class);

            foreach ($report->warnings as $warning) {
                $logger->warning('Datawell definition lint: '.$warning);
            }
        }
    }
}
