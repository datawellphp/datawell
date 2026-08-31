<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Datawell\Contracts\UserSafe;
use Datawell\DataSource;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Params;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes a resolved target (D41, D44): chunks it (or doesn't, for whole-set handlers),
 * calls the one handler signature per chunk, continues past failures and collects them,
 * and assembles the ActionReport. Nothing here checks permissions — the executor already
 * resolved the target through the scoped query and dropped ineligible rows.
 */
class Runner
{
    public function __construct(
        protected Container $container,
        protected Repository $config,
        protected ConnectionResolverInterface $connections,
        protected LoggerInterface $logger,
    ) {}

    /**
     * @param  Collection<int, object>  $rows  the resolved, authorized rows (empty for standalone)
     * @param  list<array<string, mixed>>  $skipped  already-capped skip entries from resolution
     */
    public function run(
        ServerAction $action,
        DataSource $source,
        Collection $rows,
        Params $input,
        Context $context,
        string $keyName,
        int $targeted,
        array $skipped,
        bool $skippedTruncated,
    ): ActionReport {
        $actionContext = new ActionContext($context);
        $failures = [];

        foreach ($this->chunks($action, $rows) as $chunk) {
            $failures = [...$failures, ...$this->runChunk($action, $chunk, $input, $actionContext, $keyName)];
        }

        return $this->report($action, $source, $rows, $failures, $actionContext, $keyName, $targeted, $skipped, $skippedTruncated);
    }

    /**
     * One chunk through the handler: author failures via $context->fail(); a thrown
     * exception fails the chunk's remaining rows and the runner continues (D44).
     *
     * @param  Collection<int, object>  $chunk
     * @return list<array{mixed, string}> row + reason
     */
    protected function runChunk(ServerAction $action, Collection $chunk, Params $input, ActionContext $context, string $keyName): array
    {
        $before = count($context->failures());

        try {
            if ($action->isTransactional()) {
                $this->connections->connection()->transaction(function () use ($action, $chunk, $input, $context): void {
                    $this->call($action, $chunk, $input, $context);
                });
            } else {
                $this->call($action, $chunk, $input, $context);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Datawell action failed.', [
                'action' => $action->getKey(),
                'exception' => $exception,
            ]);

            $reason = $exception instanceof UserSafe ? $exception->getMessage() : 'Failed.';
            $failed = array_map(static fn (array $failure): mixed => $failure[0], array_slice($context->failures(), $before));
            $failedKeys = array_map(fn (mixed $row): mixed => $this->keyOf($row, $keyName), $failed);

            return [
                ...array_slice($context->failures(), $before),
                ...$chunk
                    ->reject(fn (object $row): bool => in_array($this->keyOf($row, $keyName), $failedKeys, true))
                    ->map(static fn (object $row): array => [$row, $reason])
                    ->values()
                    ->all(),
            ];
        }

        return array_slice($context->failures(), $before);
    }

    /**
     * @param  Collection<int, object>  $chunk
     */
    protected function call(ServerAction $action, Collection $chunk, Params $input, ActionContext $context): void
    {
        $handler = $action->getHandler() ?? throw new UnsupportedException(sprintf('Action "%s" has no handler.', $action->getKey()));

        if (is_string($handler)) {
            $instance = $this->container->make($handler);

            if (! is_object($instance)) {
                throw new UnsupportedException(sprintf('Handler "%s" for action "%s" does not resolve to an object.', $handler, $action->getKey()));
            }

            $handler = method_exists($instance, 'handle')
                ? $instance->handle(...)
                : (is_callable($instance) ? $instance(...) : throw new UnsupportedException(sprintf('Handler "%s" for action "%s" has no handle() method and is not invokable.', $instance::class, $action->getKey())));
        }

        $handler($chunk, $input, $context);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<Collection<int, object>>
     */
    protected function chunks(ServerAction $action, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            // Standalone actions run once with an empty collection (D41's one signature).
            return $action->isStandalone() ? [$rows] : [];
        }

        if ($action->isWholeSet()) {
            return [$rows];
        }

        return array_values($rows->chunk($this->chunkSize($action))->map(static fn (Collection $chunk): Collection => $chunk->values())->all());
    }

    public function chunkSize(ServerAction $action): int
    {
        $size = $action->getChunkSize() ?? $this->config->get('datawell.actions.chunk');

        return is_int($size) && $size > 0 ? $size : 100;
    }

    public function maxFailures(): int
    {
        $max = $this->config->get('datawell.actions.max_failures');

        return is_int($max) && $max > 0 ? $max : 50;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  list<array{mixed, string}>  $failures
     * @param  list<array<string, mixed>>  $skipped
     */
    protected function report(
        ServerAction $action,
        DataSource $source,
        Collection $rows,
        array $failures,
        ActionContext $context,
        string $keyName,
        int $targeted,
        array $skipped,
        bool $skippedTruncated,
    ): ActionReport {
        $max = $this->maxFailures();
        $entries = [];
        $seen = [];

        foreach ($failures as [$row, $reason]) {
            $key = $this->keyOf($row, $keyName);

            if (in_array($key, $seen, true)) {
                continue; // first reason wins; a row fails once
            }

            $seen[] = $key;
            $entries[] = $this->entry($row, $reason, $source, $keyName);
        }

        $failed = count($seen);
        $succeeded = max(0, $rows->count() - $failed);
        $skippedCount = $targeted - $rows->count();

        return new ActionReport(
            status: $failed === 0 ? 'completed' : ($succeeded === 0 && $rows->isNotEmpty() ? 'failed' : 'partial'),
            counts: ['targeted' => $targeted, 'succeeded' => $succeeded, 'failed' => $failed, 'skipped' => $skippedCount],
            failures: array_slice($entries, 0, $max),
            truncated: count($entries) > $max || $skippedTruncated,
            skipped: $skipped,
            message: $context->getMessage(),
            links: $context->links(),
        );
    }

    /**
     * One failure/skip entry: the entity's ref (D21 machinery reused) plus the reason.
     *
     * @return array<string, mixed>
     */
    public function entry(object $row, string $reason, DataSource $source, string $keyName): array
    {
        return $source->representation()->refFor($row, $keyName)->toArray() + ['reason' => $reason];
    }

    protected function keyOf(mixed $row, string $keyName): mixed
    {
        return is_object($row) ? data_get($row, $keyName) : $row;
    }
}
