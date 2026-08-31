<?php

declare(strict_types=1);

namespace Datawell\Timezone;

use Closure;
use Datawell\Timezone\Contracts\HasTimezone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;

/**
 * Resolves the one effective timezone per request (D13):
 * user's HasTimezone → app-registered resolver → package config → app.timezone.
 */
class TimezoneResolver
{
    /** @var (Closure(Authenticatable): ?string)|null */
    protected ?Closure $resolver = null;

    public function __construct(protected Repository $config) {}

    /**
     * Register an application-level resolver (per-tenant cases and the like).
     *
     * @param  (Closure(Authenticatable): ?string)|null  $resolver
     */
    public function using(?Closure $resolver): static
    {
        $this->resolver = $resolver;

        return $this;
    }

    public function resolve(Authenticatable $user): string
    {
        if ($user instanceof HasTimezone && ($timezone = $user->timezone()) !== null) {
            return $timezone;
        }

        if ($this->resolver !== null && ($timezone = ($this->resolver)($user)) !== null) {
            return $timezone;
        }

        $configured = $this->config->get('datawell.timezone');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $app = $this->config->get('app.timezone');

        return is_string($app) && $app !== '' ? $app : 'UTC';
    }
}
