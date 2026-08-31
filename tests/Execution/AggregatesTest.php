<?php

declare(strict_types=1);

use Datawell\Executor;
use Datawell\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->seedDatabase();
});

function aggregated(array $wire, $user = null): array
{
    return app(Executor::class)->run(
        ['source' => 'document-signatures', 'parameters' => ['document_id' => 123], ...$wire],
        $user ?? test()->viewer(),
    )->toArray();
}

it('displays aggregate fields as ordinary values: a count is never null, a max of nothing is', function (): void {
    $rows = collect(aggregated(['page' => ['size' => 10]])['rows'])->keyBy('id');

    expect($rows[1]['reminders_count'])->toBe(2)
        ->and($rows[1]['last_reminder_at'])->toBe('2026-08-22T09:00:00Z')
        ->and($rows[3]['reminders_count'])->toBe(0)
        ->and($rows[3]['last_reminder_at'])->toBeNull()
        ->and($rows[5]['reminders_count'])->toBe(3);
});

it('sorts by an aggregate field in both directions, nulls last for a nullable aggregate', function (): void {
    $desc = aggregated(['sorts' => [['key' => 'reminders_count', 'direction' => 'desc']], 'page' => ['size' => 10]]);
    $asc = aggregated(['sorts' => [['key' => 'last_reminder_at', 'direction' => 'asc']], 'page' => ['size' => 10]]);

    expect(array_column($desc['rows'], 'id'))->toBe([5, 1, 2, 3, 4, 6])
        // reminders: 2 → 21st, 1 → 22nd, 5 → 23rd; 3, 4, 6 have none and come last
        ->and(array_column($asc['rows'], 'id'))->toBe([2, 1, 5, 3, 4, 6]);
});

it('pages an aggregate sort by cursor without skipping or repeating rows', function (): void {
    $walk = [];
    $after = null;

    do {
        $page = aggregated(['sorts' => [['key' => 'last_reminder_at', 'direction' => 'desc']], 'page' => ['after' => $after, 'size' => 2]]);
        $walk = [...$walk, ...array_column($page['rows'], 'id')];
        $after = $page['meta']['nextCursor'];
    } while ($page['meta']['hasMore']);

    expect($walk)->toBe([5, 1, 2, 3, 4, 6]);
});

it('filters by an aggregate field with every number operator shape', function (): void {
    $ids = fn (array $leaf): array => array_column(aggregated(['filters' => ['conditions' => [$leaf]], 'page' => ['size' => 10]])['rows'], 'id');

    expect($ids(['filter' => 'reminders_count', 'operator' => 'gte', 'value' => 2]))->toBe([5, 1])
        ->and($ids(['filter' => 'reminders_count', 'operator' => 'equals', 'value' => 0]))->toBe([6, 4, 3])
        ->and($ids(['filter' => 'reminders_count', 'operator' => 'between', 'value' => ['from' => 1, 'to' => 2]]))->toBe([2, 1])
        ->and($ids(['filter' => 'last_reminder_at', 'operator' => 'isEmpty']))->toBe([6, 4, 3])
        ->and($ids(['filter' => 'last_reminder_at', 'operator' => 'after', 'value' => '2026-08-22']))->toBe([5]);
});

it('groups by an aggregate field and measures over one', function (): void {
    $byCount = aggregated(['groupBy' => [['key' => 'reminders_count']], 'aggregates' => [['fn' => 'count']]]);

    expect($byCount['buckets'])->toBe([
        ['reminders_count' => 0, 'count' => 3],
        ['reminders_count' => 1, 'count' => 1],
        ['reminders_count' => 2, 'count' => 1],
        ['reminders_count' => 3, 'count' => 1],
    ]);

    $byStatus = aggregated(['groupBy' => [['key' => 'status']], 'aggregates' => [['fn' => 'sum', 'field' => 'reminders_count'], ['fn' => 'count']]]);

    expect($byStatus['buckets'])->toBe([
        ['status' => ['id' => 'pending', 'label' => 'Pending'], 'sum_reminders_count' => 4, 'count' => 4],
        ['status' => ['id' => 'signed', 'label' => 'Signed'], 'sum_reminders_count' => 2, 'count' => 1],
        ['status' => ['id' => 'declined', 'label' => 'Declined'], 'sum_reminders_count' => 0, 'count' => 1],
    ]);
});

it('buckets an aggregate date field by grain', function (): void {
    $utcViewer = User::fake(4, ['view-signatures'], 'UTC');
    $result = aggregated(['groupBy' => [['key' => 'last_reminder_at', 'grain' => 'day']], 'aggregates' => [['fn' => 'count']]], $utcViewer);

    expect($result['buckets'])->toBe([
        ['last_reminder_at' => ['id' => '2026-08-21', 'label' => '21 Aug 2026'], 'count' => 1],
        ['last_reminder_at' => ['id' => '2026-08-22', 'label' => '22 Aug 2026'], 'count' => 1],
        ['last_reminder_at' => ['id' => '2026-08-23', 'label' => '23 Aug 2026'], 'count' => 1],
        ['last_reminder_at' => null, 'count' => 3],
    ]);
});

it('counts through the other direction too: documents by signatures', function (): void {
    $result = app(Executor::class)->run(['source' => 'documents', 'sorts' => [['key' => 'signatures_count', 'direction' => 'desc']]], test()->viewer())->toArray();

    expect(array_map(static fn (array $row): array => [$row['id'], $row['signatures_count']], $result['rows']))->toBe([[123, 6], [500, 1]]);
});
