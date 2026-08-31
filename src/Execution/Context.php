<?php

declare(strict_types=1);

namespace Datawell\Execution;

use Datawell\Exceptions\UnsupportedException;
use Datawell\Relations\RelationResolver;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Everything a request is evaluated against, resolved once: the acting user, the
 * channel, the effective timezone (D13), "now" in that timezone (D12), and the
 * request-scoped relation resolver every strategy runs through (D50).
 */
final class Context
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly Channel $channel,
        public readonly string $timezone,
        public readonly DateTimeImmutable $now,
        private readonly ?RelationResolver $relations = null,
    ) {}

    public function relations(): RelationResolver
    {
        return $this->relations ?? throw new UnsupportedException('This context was built without a relation resolver; relation-backed fields need one.');
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }
}
