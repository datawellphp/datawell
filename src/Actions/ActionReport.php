<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The one result shape every invocation produces (D44) — single is a bulk of one.
 * Status is mechanically derived; failures and skips are capped with an explicit
 * `truncated` flag (no silent caps, D07); reasons are user-safe by construction.
 *
 * @implements Arrayable<string, mixed>
 */
final class ActionReport implements Arrayable, JsonSerializable
{
    /**
     * @param  'completed'|'partial'|'failed'|'queued'  $status
     * @param  array{targeted: int, succeeded: int, failed: int, skipped: int}  $counts
     * @param  list<array<string, mixed>>  $failures  capped entries: entity ref + reason
     * @param  list<array<string, mixed>>  $skipped  capped entries: entity ref + reason
     * @param  list<array{label: string, url: string}>  $links
     */
    public function __construct(
        public readonly string $status,
        public readonly array $counts,
        public readonly array $failures = [],
        public readonly bool $truncated = false,
        public readonly array $skipped = [],
        public readonly ?string $message = null,
        public readonly array $links = [],
        public readonly ?string $runId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $report = [
            'status' => $this->status,
            'counts' => $this->counts,
            'failures' => $this->failures,
            'truncated' => $this->truncated,
            'skipped' => $this->skipped,
        ];

        if ($this->message !== null) {
            $report['message'] = $this->message;
        }

        if ($this->links !== []) {
            $report['links'] = $this->links;
        }

        if ($this->runId !== null) {
            $report['runId'] = $this->runId;
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
