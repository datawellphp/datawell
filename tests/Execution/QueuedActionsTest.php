<?php

declare(strict_types=1);

use Datawell\Actions\Runs\ActionRun;
use Datawell\Execution\Channel;
use Datawell\Executor;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->seedDatabase();
});

/**
 * Queued jobs rehydrate their user from the database, so these tests act as a
 * persisted user (outside the people source's workspace, to leave its rows alone).
 */
function opsUser(): User
{
    return User::query()->create([
        'name' => 'Ops Bot',
        'email' => 'ops@acme.com',
        'workspace_id' => 2,
        'abilities' => ['view-signatures'],
    ]);
}

it('queues a run past the sync limit and folds every chunk into the run row', function (): void {
    config()->set('datawell.actions.sync_limit', 1);
    config()->set('datawell.actions.chunk', 1);

    $user = opsUser();
    $report = app(Executor::class)->act([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'action' => 'decline_stale',
        'target' => ['ids' => [2, 5]],
    ], $user)->toArray();

    // The sync queue driver ran the batch inline, so the handle already reads finished.
    expect($report['runId'])->toBeString()
        ->and($report['status'])->toBe('completed')
        ->and($report['counts'])->toBe(['targeted' => 2, 'succeeded' => 2, 'failed' => 0, 'skipped' => 0])
        ->and(Signature::query()->whereIn('id', [2, 5])->pluck('status')->unique()->all())->toBe(['declined']);

    $run = ActionRun::query()->findOrFail($report['runId']);

    expect($run->batch_id)->not->toBeNull()
        ->and($run->processed)->toBe(2)
        ->and($run->channel)->toBe('direct')
        ->and($run->isFinished())->toBeTrue();
});

it('always queues an action declared queued(), and its chunks report failures', function (): void {
    config()->set('datawell.actions.chunk', 1);

    $user = opsUser();
    $report = app(Executor::class)->act([
        'source' => 'people',
        'action' => 'deactivate',
        'target' => ['ids' => [1, 5]], // Anna is an admin: ineligible, skipped at dispatch
    ], $user)->toArray();

    expect($report['runId'])->toBeString()
        ->and($report['counts'])->toBe(['targeted' => 2, 'succeeded' => 1, 'failed' => 0, 'skipped' => 1])
        ->and($report['skipped'][0]['reason'])->toBe('Not allowed.')
        ->and((bool) User::query()->findOrFail(5)->active)->toBeFalse()
        ->and((bool) User::query()->findOrFail(1)->active)->toBeTrue();
});

it('reports a queued run\'s outcome through progress(), for its own user only', function (): void {
    config()->set('datawell.actions.sync_limit', 1);

    $user = opsUser();
    $executor = app(Executor::class);
    $runId = $executor->act([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'action' => 'send_reminder',
        'target' => ['ids' => [2, 6]], // 6 has no signer: author failure inside the batch
    ], $user)->runId;

    $progress = $executor->progress((string) $runId, $user);

    expect($progress?->status)->toBe('partial')
        ->and($progress->counts)->toBe(['targeted' => 2, 'succeeded' => 1, 'failed' => 1, 'skipped' => 0])
        ->and($progress->failures[0]['id'])->toBe(6)
        ->and($progress->failures[0]['reason'])->toBe('No signer to remind.')
        ->and($progress->progress)->toBeNull();

    // Someone else's run id reads as nonexistent (D18), as does a made-up one.
    expect($executor->progress((string) $runId, test()->viewer()))->toBeNull()
        ->and($executor->progress('018f0000-0000-7000-8000-000000000000', $user))->toBeNull();
});

it('records synchronous runs under record = always, channel and approval included', function (): void {
    config()->set('datawell.actions.record', 'always');

    $user = opsUser();
    $report = app(Executor::class)->act([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'action' => 'send_reminder',
        'target' => ['ids' => [2]],
        'approval' => 'usr-grant-7f3a',
    ], $user, Channel::DelegatedInteractive)->toArray();

    expect($report)->not->toHaveKey('runId');

    $run = ActionRun::query()->sole();

    expect($run->action_key)->toBe('send_reminder')
        ->and($run->status)->toBe('completed')
        ->and($run->channel)->toBe('delegatedInteractive')
        ->and($run->approval)->toBe('usr-grant-7f3a')
        ->and($run->succeeded)->toBe(1)
        ->and($run->isFinished())->toBeTrue();
});

it('does not record synchronous runs by default', function (): void {
    app(Executor::class)->act([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'action' => 'send_reminder',
        'target' => ['ids' => [2]],
    ], opsUser());

    expect(ActionRun::query()->count())->toBe(0);
});

it('prunes runs past the retention window', function (): void {
    config()->set('datawell.actions.sync_limit', 1);

    $user = opsUser();
    $runId = app(Executor::class)->act([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'action' => 'decline_stale',
        'target' => ['ids' => [2, 5]],
    ], $user)->runId;

    ActionRun::query()->whereKey($runId)->update(['created_at' => now()->subDays(60)]);

    $pruned = (new ActionRun)->pruneAll();

    expect($pruned)->toBe(1)->and(ActionRun::query()->count())->toBe(0);
});
