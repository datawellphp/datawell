<?php

declare(strict_types=1);

namespace Datawell;

use Datawell\Exceptions\SourceNotFoundException;
use Datawell\Execution\Channel;
use Datawell\Execution\Context;
use Datawell\Query\QueryRequest;
use Datawell\Timezone\TimezoneResolver;
use Datawell\Validation\RequestValidator;
use Datawell\Validation\ValidationException;
use Datawell\Validation\ValidationReport;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The one enforcement point (D05). Every consumer — table, AI, charts, exports — hands
 * a QueryRequest to this pipeline; nothing else touches a query builder.
 */
class Executor
{
    public function __construct(
        protected Registry $registry,
        protected TimezoneResolver $timezones,
        protected RequestValidator $validator,
    ) {}

    /**
     * Dry-run validation (D38): check a request against the current per-user schema
     * without executing. Hidden sources fail as not-found (D18).
     *
     * @param  QueryRequest|array<string, mixed>  $request
     *
     * @throws SourceNotFoundException
     * @throws ValidationException when the wire shape itself is malformed
     */
    public function validate(QueryRequest|array $request, Authenticatable $user, Channel $channel = Channel::Direct): ValidationReport
    {
        $request = $request instanceof QueryRequest ? $request : QueryRequest::fromArray($request);
        $source = $this->registry->findFor($request->source, $user);
        $context = $this->context($user, $channel);

        [, $errors] = $this->validator->validate($source, $request, $context);

        return new ValidationReport($request, $errors);
    }

    public function context(Authenticatable $user, Channel $channel = Channel::Direct): Context
    {
        $timezone = $this->timezones->resolve($user);

        return new Context($user, $channel, $timezone, new DateTimeImmutable('now', new DateTimeZone($timezone)));
    }
}
