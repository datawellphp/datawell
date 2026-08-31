<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Datawell\Execution\Channel;
use Datawell\Execution\Context;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a handler receives (D41, D44): the execution context, plus the collector —
 * `fail(row, reason)` marks granular failures (unmarked rows succeed), `message()` sets
 * the report's author message, `link()` adds follow-up links. Reasons given here are the
 * author's own strings and go to the wire verbatim, so write them for the end user.
 */
final class ActionContext
{
    public readonly Authenticatable $user;

    public readonly Channel $channel;

    public readonly string $timezone;

    public readonly DateTimeImmutable $now;

    /** @var list<array{mixed, string}> */
    private array $failures = [];

    private ?string $message = null;

    /** @var list<array{label: string, url: string}> */
    private array $links = [];

    public function __construct(public readonly Context $context)
    {
        $this->user = $context->user;
        $this->channel = $context->channel;
        $this->timezone = $context->timezone;
        $this->now = $context->now;
    }

    /**
     * Mark one row as failed, with a reason safe to show the acting user.
     */
    public function fail(mixed $row, string $reason): void
    {
        $this->failures[] = [$row, $reason];
    }

    /**
     * The report's optional author message ("Reminder sent to 1 signer.").
     */
    public function message(string $message): void
    {
        $this->message = $message;
    }

    /**
     * A follow-up link for the report (D44) — app-relative, like every URL here (D34).
     */
    public function link(string $label, string $url): void
    {
        $this->links[] = ['label' => $label, 'url' => $url];
    }

    /**
     * @internal
     *
     * @return list<array{mixed, string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @internal
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @internal
     *
     * @return list<array{label: string, url: string}>
     */
    public function links(): array
    {
        return $this->links;
    }
}
