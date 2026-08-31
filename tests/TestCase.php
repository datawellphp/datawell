<?php

declare(strict_types=1);

namespace Datawell\Tests;

use Datawell\DatawellServiceProvider;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Models\User;
use Datawell\Tests\Fixtures\Policies\SignaturePolicy;
use Datawell\Tests\Fixtures\Sources\Documents;
use Datawell\Tests\Fixtures\Sources\DocumentSignatures;
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
        $app['config']->set('datawell.sources', [DocumentSignatures::class, Documents::class, Tags::class]);
        $app['config']->set('datawell.lint.enabled', true);
        $app['config']->set('app.timezone', 'UTC');
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
}
