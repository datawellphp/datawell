<?php

declare(strict_types=1);

namespace Datawell\Actions\Runs;

use Datawell\Actions\ActionReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * One recorded invocation (D45): created at dispatch for queued runs (tier 1), or for
 * every run under `datawell.actions.record = 'always'` (tier 2 — the audit trail, with
 * channel and approval reference). Chunk jobs fold their outcomes in as they finish;
 * progress(runId) reads this row. Prunable after `datawell.actions.retention_days`.
 *
 * @property string $id
 * @property string $source_key
 * @property string $action_key
 * @property string $status
 * @property string $channel
 * @property string|null $user_id
 * @property string|null $approval
 * @property int $targeted
 * @property int $processed
 * @property int $succeeded
 * @property int $failed
 * @property int $skipped
 * @property list<array<string, mixed>> $failures
 * @property bool $truncated
 * @property list<array<string, mixed>> $skipped_rows
 * @property list<array{label: string, url: string}> $links
 * @property string|null $message
 * @property string|null $batch_id
 * @property Carbon|null $finished_at
 */
class ActionRun extends Model
{
    use HasUuids;
    use Prunable;

    protected $table = 'datawell_action_runs';

    protected $guarded = [];

    protected $casts = [
        'failures' => 'array',
        'skipped_rows' => 'array',
        'links' => 'array',
        'truncated' => 'boolean',
        'finished_at' => 'datetime',
    ];

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * The run's total rows to process (targeted minus dispatch-time skips).
     */
    public function total(): int
    {
        return max(0, $this->targeted - $this->skipped);
    }

    /**
     * The run as the one report shape (D44): counts so far while running,
     * the final report once finished.
     */
    public function report(): ActionReport
    {
        return new ActionReport(
            status: $this->status,
            counts: ['targeted' => $this->targeted, 'succeeded' => $this->succeeded, 'failed' => $this->failed, 'skipped' => $this->skipped],
            failures: $this->failures ?? [],
            truncated: $this->truncated,
            skipped: $this->skipped_rows ?? [],
            message: $this->message,
            links: $this->links ?? [],
            runId: $this->id,
            progress: $this->isFinished() ? null : ['processed' => $this->processed, 'total' => $this->total()],
        );
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('datawell.actions.retention_days');
        $days = is_int($days) && $days > 0 ? $days : 30;

        return static::query()->where('created_at', '<=', now()->subDays($days));
    }
}
