<?php

declare(strict_types=1);

namespace Datawell\Actions\Jobs;

use Datawell\Actions\Runner;
use Datawell\Actions\Runs\ActionRun;
use Datawell\Actions\ServerAction;
use Datawell\Execution\Channel;
use Datawell\Executor;
use Datawell\Params;
use Datawell\Registry;
use Datawell\Relations\RelationResolver;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * One chunk of a queued action run (D41, D45): re-resolves the source, action and rows
 * at run time — nothing serialized closes over a closure — executes through the Runner,
 * and folds the outcome into the run row. A chunk never fails the batch: any failure
 * becomes failed rows on the report (continue-and-collect, D44).
 */
class RunChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $runId,
        public string $sourceKey,
        public string $actionKey,
        public array $ids,
        public array $parameters,
        public array $input,
        public Authenticatable $user,
        public Channel $channel,
    ) {}

    public function handle(Registry $registry, Executor $executor, Runner $runner): void
    {
        if ($this->batch()?->cancelled() ?? false) {
            return;
        }

        $run = ActionRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $failures = [];
        $rows = collect();
        $message = null;
        $links = [];

        try {
            $source = $registry->findFor($this->sourceKey, $this->user);
            $definition = $source->definition();
            $action = $definition->actions()[$this->actionKey] ?? null;

            if (! $action instanceof ServerAction) {
                throw new RuntimeException(sprintf('Action "%s" is no longer a server action.', $this->actionKey));
            }

            $context = $executor->context($this->user, $this->channel);
            $params = Params::make($this->parameters);
            $query = $source->query($params);
            $keyName = $query instanceof Builder ? $query->getModel()->getKeyName() : 'id';

            /** @var Collection<int, object> $rows */
            $rows = $query->whereIn(RelationResolver::qualify($query, $keyName), $this->ids)->get()->values();

            // Rows gone or newly ineligible since dispatch fail rather than vanish (D44).
            $foundKeys = $rows->map(static fn (object $row): mixed => data_get($row, $keyName))->all();

            foreach ($this->ids as $id) {
                if (! in_array($id, $foundKeys, false)) {
                    $failures[] = ['id' => $id, 'reason' => 'Not found.'];
                }
            }

            [$eligible, $ineligible] = $rows->partition(fn (object $row): bool => $action->authorizes($this->user, $row));

            foreach ($ineligible as $row) {
                $failures[] = $runner->entry($row, 'Not allowed.', $source, $keyName);
            }

            [$entries, $actionContext] = $runner->collect($action, $source, $eligible->values(), Params::make($this->input), $context, $keyName);
            $failures = [...$failures, ...$entries];
            $message = $actionContext->getMessage();
            $links = $actionContext->links();
        } catch (Throwable $exception) {
            report($exception);
            $failures = array_map(static fn (int|string $id): array => ['id' => $id, 'reason' => 'Failed.'], $this->ids);
        }

        $this->record($run, count($this->ids), $failures, $message, $links, $runner->maxFailures());
    }

    /**
     * Fold this chunk's outcome into the run row, atomically.
     *
     * @param  list<array<string, mixed>>  $failures
     * @param  list<array{label: string, url: string}>  $links
     */
    protected function record(ActionRun $run, int $processed, array $failures, ?string $message, array $links, int $maxFailures): void
    {
        $run->getConnection()->transaction(function () use ($run, $processed, $failures, $message, $links, $maxFailures): void {
            /** @var ActionRun $fresh */
            $fresh = ActionRun::query()->lockForUpdate()->findOrFail($run->id);

            $failed = count($failures);
            $existing = $fresh->failures ?? [];
            $kept = array_slice([...$existing, ...$failures], 0, $maxFailures);

            $fresh->forceFill([
                'processed' => $fresh->processed + $processed,
                'failed' => $fresh->failed + $failed,
                'succeeded' => $fresh->succeeded + ($processed - $failed),
                'failures' => $kept,
                'truncated' => $fresh->truncated || count($existing) + $failed > $maxFailures,
                'message' => $message ?? $fresh->message,
                'links' => $links !== [] ? $links : $fresh->links,
            ]);

            if ($fresh->processed >= $fresh->total()) {
                $fresh->forceFill([
                    'status' => $fresh->failed === 0 ? 'completed' : ($fresh->succeeded === 0 ? 'failed' : 'partial'),
                    'finished_at' => now(),
                ]);
            }

            $fresh->save();
        });
    }
}
