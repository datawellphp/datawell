<?php

declare(strict_types=1);

namespace Datawell\Tests;

use Datawell\DatawellServiceProvider;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Models\User;
use Datawell\Tests\Fixtures\Policies\SignaturePolicy;
use Datawell\Tests\Fixtures\Sources\Documents;
use Datawell\Tests\Fixtures\Sources\DocumentSignatures;
use Datawell\Tests\Fixtures\Sources\People;
use Datawell\Tests\Fixtures\Sources\Tags;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DatawellServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('datawell.sources', [DocumentSignatures::class, Documents::class, Tags::class, People::class]);
        $app['config']->set('datawell.lint.enabled', true);
        $app['config']->set('app.timezone', 'UTC');

        // The contract suite runs on SQLite by default; CI (and a developer with the
        // service at hand) points it at MySQL or Postgres through DATAWELL_DB_*.
        $driver = getenv('DATAWELL_DB') ?: 'sqlite';

        if ($driver !== 'sqlite') {
            $app['config']->set('database.default', 'datawell');
            $app['config']->set('database.connections.datawell', [
                'driver' => $driver,
                'host' => getenv('DATAWELL_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DATAWELL_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('DATAWELL_DB_DATABASE') ?: 'datawell',
                'username' => getenv('DATAWELL_DB_USERNAME') ?: 'root',
                'password' => getenv('DATAWELL_DB_PASSWORD') ?: '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'timezone' => '+00:00',
            ]);
        }
    }

    protected function driver(): string
    {
        return getenv('DATAWELL_DB') ?: 'sqlite';
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('view-signatures', fn (User $user): bool => $user->hasAbility('view-signatures'));
        Gate::define('view-contact-details', fn (User $user): bool => $user->hasAbility('view-contact-details'));
        Gate::define('viewSignatures', fn (User $user, mixed $document): bool => true);
        Gate::policy(Signature::class, SignaturePolicy::class);
    }

    /**
     * A user who may see signatures but not contact details, in New York.
     */
    protected function viewer(): User
    {
        return User::fake(1, ['view-signatures'], 'America/New_York');
    }

    protected function privilegedViewer(): User
    {
        return User::fake(2, ['view-signatures', 'view-contact-details'], 'Europe/London');
    }

    protected function outsider(): User
    {
        return User::fake(3);
    }

    /**
     * Create the fixture tables on the in-memory SQLite connection and seed people.
     */
    protected function seedDatabase(): void
    {
        $schema = require __DIR__.'/Fixtures/Database/schema.php';
        $schema->create();
        $schema->seedPeople();
        $schema->seedDocuments();
    }
}
