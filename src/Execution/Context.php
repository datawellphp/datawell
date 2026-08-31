<?php

declare(strict_types=1);

namespace Datawell\Execution;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Everything a request is evaluated against, resolved once: the acting user, the
 * channel, the effective timezone (D13) and "now" in that timezone (D12).
 */
final class Context
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly Channel $channel,
        public readonly string $timezone,
        public readonly DateTimeImmutable $now,
    ) {}

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }
}
