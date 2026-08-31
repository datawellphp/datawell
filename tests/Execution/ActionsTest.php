<?php

declare(strict_types=1);

use Datawell\Execution\Channel;
use Datawell\Executor;
use Datawell\Tests\Fixtures\Models\Reminder;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Validation\ValidationException;

beforeEach(function (): void {
    $this->seedDatabase();
});

function act(array $wire, $user = null, Channel $channel = Channel::Direct): array
{
    return app(Executor::class)->act(
        ['source' => 'document-signatures', 'parameters' => ['document_id' => 123], ...$wire],
        $user ?? test()->viewer(),
        $channel,
    )->toArray();
}

it('runs a single-row action and reports the examples-doc shape', function (): void {
    $report = act(['action' => 'send_reminder', 'target' => ['ids' => [2]], 'input' => ['message' => 'Nudge']]);

    expect($report)->toBe([
        'status' => 'completed',
        'counts' => ['targeted' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0],
        'failures' => [],
        'truncated' => false,
        'skipped' => [],
        'message' => 'Reminder sent to 1 signer.',
    ])->and(Reminder::query()->where('signature_id', 2)->count())->toBe(2);
});

it('collects author failures and continues: partial status with per-row reasons', function (): void {
    // 6 has no signer (author fail); 1 succeeds.
    $report = act(['action' => 'send_reminder', 'target' => ['ids' => [6, 1]]]);

    expect($report['status'])->toBe('partial')
        ->and($report['counts'])->toBe(['targeted' => 2, 'succeeded' => 1, 'failed' => 1, 'skipped' => 0])
        ->and($report['failures'])->toBe([['id' => 6, 'label' => '', 'url' => '/documents/123/signatures/6', 'reason' => 'No signer to remind.']])
        ->and($report['message'])->toBe('Reminder sent to 1 signer.');
});

it('turns an exception into failures for the chunk, user-safe reasons only', function (): void {
    // 3 is Cara (throws user-safe); with chunk size 1 the others still run.
    config()->set('datawell.actions.chunk', 1);

    $report = act(['action' => 'send_reminder', 'target' => ['ids' => [3, 2]]]);

    expect($report['status'])->toBe('partial')
        ->and(collect($report['failures'])->firstWhere('id', 3)['reason'])->toBe('Mailbox rejected the address')
        ->and($report['counts']['succeeded'])->toBe(1);
});

it('fails the whole chunk\'s unmarked rows when the handler throws (D44)', function (): void {
    // One chunk resolving in key order [1, 3]: 1 got its reminder before 3 threw, but the
    // runner cannot know that — every row of the chunk not explicitly failed reports failed.
    $before = Reminder::query()->count();
    $report = act(['action' => 'send_reminder', 'target' => ['ids' => [1, 3]]]);

    expect($report['counts'])->toBe(['targeted' => 2, 'succeeded' => 0, 'failed' => 2, 'skipped' => 0])
        ->and($report['status'])->toBe('failed')
        ->and(array_column($report['failures'], 'id'))->toBe([1, 3])
        ->and(array_unique(array_column($report['failures'], 'reason')))->toBe(['Mailbox rejected the address'])
        ->and(Reminder::query()->count())->toBe($before + 1);
});

it('skips out-of-scope ids as not found, indistinguishable from nonexistent ones', function (): void {
    // 7 exists on document 500 (out of scope); 999 does not exist at all.
    $report = act(['action' => 'send_reminder', 'target' => ['ids' => [1, 7, 999]]]);

    expect($report['counts'])->toBe(['targeted' => 3, 'succeeded' => 1, 'failed' => 0, 'skipped' => 2])
        ->and($report['skipped'])->toBe([
            ['id' => 7, 'reason' => 'Not found.'],
            ['id' => 999, 'reason' => 'Not found.'],
        ])
        ->and($report['status'])->toBe('completed');
});

it('re-enforces per-row authorization at execution: ineligible rows skip as not allowed', function (): void {
    // decline_stale authorizes pending rows only; 1 is signed.
    $report = act(['action' => 'decline_stale', 'target' => ['ids' => [1, 2]]]);

    expect($report['counts'])->toBe(['targeted' => 2, 'succeeded' => 1, 'failed' => 0, 'skipped' => 1])
        ->and($report['skipped'][0]['id'])->toBe(1)
        ->and($report['skipped'][0]['reason'])->toBe('Not allowed.')
        ->and(Signature::query()->find(2)->status)->toBe('declined')
        ->and(Signature::query()->find(1)->status)->toBe('signed');
});

it('resolves a queryScope target through filters, defaults, authorizeQuery and except', function (): void {
    $report = act(['action' => 'decline_stale', 'target' => [
        'query' => ['filters' => ['conditions' => [['filter' => 'signer', 'operator' => 'notIn', 'value' => [1]]]]],
        'except' => [6],
    ]]);

    // Pending (authorizeQuery) ∧ signer not Anna ∧ not 6: rows 2 and 5.
    expect($report['counts'])->toBe(['targeted' => 2, 'succeeded' => 2, 'failed' => 0, 'skipped' => 0])
        ->and(Signature::query()->whereIn('id', [2, 5])->pluck('status')->unique()->all())->toBe(['declined'])
        ->and(Signature::query()->find(6)->status)->toBe('pending');
});

it('validates the target query against the per-user schema', function (): void {
    expect(fn () => act(['action' => 'decline_stale', 'target' => ['query' => ['filters' => ['conditions' => [['filter' => 'signer_email', 'operator' => 'contains', 'value' => 'x']]]]]]))
        ->toThrow(ValidationException::class, 'Unknown filter "signer_email".');
});

it('rejects target shapes the action does not declare', function (): void {
    expect(fn () => act(['action' => 'send_reminder', 'target' => ['query' => []]]))
        ->toThrow(ValidationException::class, 'Action "send_reminder" does not accept a query target.')
        ->and(fn () => act(['action' => 'void_signature', 'target' => ['ids' => [1, 2]]]))
        ->toThrow(ValidationException::class, 'Action "void_signature" accepts one row at a time.');
});

it('validates action input through the parameter machinery', function (): void {
    expect(fn () => act(['action' => 'send_reminder', 'target' => ['ids' => [1]], 'input' => ['message' => str_repeat('x', 501)]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => act(['action' => 'send_reminder', 'target' => ['ids' => [1]], 'input' => ['surprise' => 1]]))
        ->toThrow(ValidationException::class, 'Unknown input "surprise".');
});

it('treats hidden and nonexistent actions identically', function (): void {
    expect(fn () => act(['action' => 'nope', 'target' => ['ids' => [1]]]))
        ->toThrow(ValidationException::class, 'Unknown action "nope".')
        // humanOnly on a delegated channel reads as unknown (D37).
        ->and(fn () => act(['action' => 'void_signature', 'target' => ['ids' => [1]], 'approval' => 'usr-grant-1'], channel: Channel::DelegatedInteractive))
        ->toThrow(ValidationException::class, 'Unknown action "void_signature".');
});

it('refuses link and client actions server-side', function (): void {
    expect(fn () => act(['action' => 'edit', 'target' => ['ids' => [1]]]))
        ->toThrow(ValidationException::class, 'Action "edit" is a link action and does not execute server-side.');
});

it('verifies consent on delegated channels (D37)', function (): void {
    // send_reminder confirms WhenDelegated: direct runs free, delegated needs approval.
    expect(act(['action' => 'send_reminder', 'target' => ['ids' => [2]]])['status'])->toBe('completed');

    expect(fn () => act(['action' => 'send_reminder', 'target' => ['ids' => [2]]], channel: Channel::DelegatedInteractive))
        ->toThrow(ValidationException::class, 'Action "send_reminder" requires user approval on this channel; include the approval reference.');

    expect(act(['action' => 'send_reminder', 'target' => ['ids' => [2]], 'approval' => 'usr-grant-7f3a'], channel: Channel::DelegatedInteractive)['status'])->toBe('completed');

    expect(fn () => act(['action' => 'send_reminder', 'target' => ['ids' => [2]], 'approval' => 'usr-grant-7f3a'], channel: Channel::DelegatedNonInteractive))
        ->toThrow(ValidationException::class, 'Action "send_reminder" requires confirmation and cannot run on a non-interactive channel.');
});

it('floors queryScope actions to Always: approval required even where declaration says less', function (): void {
    expect(fn () => act(['action' => 'decline_stale', 'target' => ['ids' => [2]]], channel: Channel::DelegatedInteractive))
        ->toThrow(ValidationException::class, 'requires user approval');
});

it('masks source authorization as an invalid parameter for actions too', function (): void {
    expect(fn () => app(Executor::class)->act(
        ['source' => 'document-signatures', 'parameters' => ['document_id' => 999], 'action' => 'send_reminder', 'target' => ['ids' => [1]]],
        test()->viewer(),
    ))->toThrow(ValidationException::class, 'Invalid document_id.');
});

it('rejects malformed wire shapes strictly', function (): void {
    expect(fn () => act(['action' => 'send_reminder', 'target' => ['ids' => []]]))
        ->toThrow(ValidationException::class, 'Expected a non-empty list of ids.')
        ->and(fn () => act(['action' => 'send_reminder', 'target' => ['rows' => [1]]]))
        ->toThrow(ValidationException::class, '"target" must be {ids: [...]} or {query: {...}, except: [...]}.')
        ->and(fn () => act(['action' => 'send_reminder', 'target' => ['ids' => [1]], 'extra' => true]))
        ->toThrow(ValidationException::class, 'Unknown key "extra".')
        ->and(fn () => act(['action' => 'send_reminder']))
        ->toThrow(ValidationException::class, 'Action "send_reminder" needs a target.')
        ->and(fn () => act(['action' => 'decline_stale', 'target' => ['query' => ['sorts' => [['key' => 'requested_at', 'direction' => 'asc']]]]]))
        ->toThrow(ValidationException::class, 'A target query does not accept "sorts"; it only selects rows.');
});

it('runs a standalone action once with no rows', function (): void {
    $report = app(Executor::class)->act(['source' => 'people', 'action' => 'refresh_directory'], test()->viewer())->toArray();

    expect($report['status'])->toBe('completed')
        ->and($report['counts'])->toBe(['targeted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0])
        ->and($report['message'])->toBe('Directory refresh scheduled.');

    expect(fn () => app(Executor::class)->act(['source' => 'people', 'action' => 'refresh_directory', 'target' => ['ids' => [1]]], test()->viewer()))
        ->toThrow(ValidationException::class, 'Action "refresh_directory" is standalone and takes no target.');
});
