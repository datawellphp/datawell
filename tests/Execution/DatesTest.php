<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Datawell\Executor;
use Datawell\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->seedDatabase();
    // 2026-08-18 15:00 UTC = 11:00 in New York (EDT, UTC-4), 16:00 in London (BST).
    CarbonImmutable::setTestNow('2026-08-18 15:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function seen(array $conditions, ?User $user = null, array $extra = []): array
{
    $result = app(Executor::class)->run([
        'source' => 'people',
        'filters' => ['conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => true], ...$conditions]],
        'page' => ['size' => 50],
        ...$extra,
    ], $user ?? test()->viewer())->toArray();

    return array_column($result['rows'], 'name');
}

it('expands "on" for an instant to the user\'s local day, converted to UTC', function (): void {
    // New York day = [2026-08-18T04:00Z, 2026-08-19T04:00Z): Ben (04:30Z) and Cara (03:59Z next day) — not Anna (03:30Z) or Eli (04:00Z next day).
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'on', 'value' => '2026-08-18']]))->toBe(['Ben Okoro', 'Cara Smith']);

    // The same request for a UTC user: Anna and Ben fall on the 18th; Cara and Eli on the 19th.
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'on', 'value' => '2026-08-18']], User::fake(9)))->toBe(['Anna Smith', 'Ben Okoro']);
});

it('never converts a wall date', function (): void {
    expect(seen([['filter' => 'joined_on', 'operator' => 'on', 'value' => '2026-08-18']]))->toBe(['Anna Smith', 'Cara Smith'])
        ->and(seen([['filter' => 'joined_on', 'operator' => 'on', 'value' => '2026-08-18']], User::fake(9)))->toBe(['Anna Smith', 'Cara Smith']);
});

it('handles the DST transition day, which is 23 hours long in New York', function (): void {
    // 2026-03-08 in New York = [2026-03-08T05:00Z, 2026-03-09T04:00Z): Gus only.
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'on', 'value' => '2026-03-08']]))->toBe([])
        ->and(seen([['filter' => 'last_seen_at', 'operator' => 'on', 'value' => '2026-03-08'], ['filter' => 'active', 'operator' => 'is', 'value' => false]]))->toBe([]);

    $names = fn (array $c) => array_column(app(Executor::class)->run(['source' => 'people', 'filters' => ['conditions' => $c], 'page' => ['size' => 50]], test()->viewer())->toArray()['rows'], 'name');

    expect($names([['filter' => 'active', 'operator' => 'is', 'value' => false], ['filter' => 'last_seen_at', 'operator' => 'on', 'value' => '2026-03-08']]))->toBe(['Gus Reyes'])
        ->and($names([['filter' => 'last_seen_at', 'operator' => 'between', 'value' => ['from' => '2026-03-07', 'to' => '2026-03-09']]]))->toBe(['Fay Chen', 'Hana Ito']);
});

it('treats before/after as day boundaries for dates and as instants for date-times', function (): void {
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'before', 'value' => '2026-08-18']]))->toBe(['Anna Smith', 'Fay Chen', 'Hana Ito', 'Ivy Smithson', 'Jon Alder'])
        ->and(seen([['filter' => 'last_seen_at', 'operator' => 'after', 'value' => '2026-08-18']]))->toBe(['Eli Brown'])
        ->and(seen([['filter' => 'last_seen_at', 'operator' => 'after', 'value' => '2026-08-18T04:00:00Z']]))->toBe(['Ben Okoro', 'Cara Smith', 'Eli Brown'])
        ->and(seen([['filter' => 'joined_on', 'operator' => 'after', 'value' => '2026-08-01']]))->toBe(['Anna Smith', 'Ben Okoro', 'Cara Smith']);
});

it('resolves relative values in the effective timezone', function (): void {
    // ago 1 day from 11:00 NY on the 18th = 2026-08-17T15:00Z: everything seen after that instant.
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'after', 'value' => ['relative' => 'ago', 'n' => 1, 'unit' => 'days']]]))->toBe(['Anna Smith', 'Ben Okoro', 'Cara Smith', 'Eli Brown']);

    // today (NY) = the 18th's local day.
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'on', 'value' => ['relative' => 'today']]]))->toBe(['Ben Okoro', 'Cara Smith']);

    // last 7 days = the seven complete days before today: [Aug 11, Aug 18) NY — Anna (Aug 17 23:30 NY) yes, Ben (Aug 18 00:30 NY) no.
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'between', 'value' => ['relative' => 'last', 'n' => 7, 'unit' => 'days']]]))->toBe(['Anna Smith']);

    // this month = August in NY.
    expect(seen([['filter' => 'last_seen_at', 'operator' => 'between', 'value' => ['relative' => 'this', 'unit' => 'month']]]))->toBe(['Anna Smith', 'Ben Okoro', 'Cara Smith', 'Eli Brown', 'Ivy Smithson']);

    // Wall dates: joined this month (no conversion; NY and UTC agree on the calendar).
    expect(seen([['filter' => 'joined_on', 'operator' => 'between', 'value' => ['relative' => 'this', 'unit' => 'month']]]))->toBe(['Anna Smith', 'Ben Okoro', 'Cara Smith', 'Ivy Smithson']);
});

it('serializes instants as UTC ISO-8601 and wall dates as plain dates', function (): void {
    $rows = app(Executor::class)->run(['source' => 'people', 'select' => ['joined_on', 'last_seen_at'], 'page' => ['size' => 2]], $this->viewer())->toArray()['rows'];

    expect($rows[0]['joined_on'])->toBe('2026-08-18')
        ->and($rows[0]['last_seen_at'])->toBe('2026-08-18T03:30:00Z');
});

it('sorts nullable columns with nulls last on every driver and cursors across the null boundary', function (): void {
    $seenNames = [];
    $after = null;

    do {
        $page = app(Executor::class)->run([
            'source' => 'people',
            'filters' => ['conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => false]]],
            'sorts' => [['key' => 'last_seen_at', 'direction' => 'desc']],
            'page' => ['size' => 1, 'after' => $after],
        ], $this->viewer())->toArray();
        array_push($seenNames, ...array_column($page['rows'], 'name'));
        $after = $page['meta']['nextCursor'];
    } while ($page['meta']['hasMore']);

    expect($seenNames)->toBe(['Gus Reyes', 'Dev Patel']);

    $all = seen([], null, ['sorts' => [['key' => 'age', 'direction' => 'asc']]]);
    expect($all)->toBe(['Cara Smith', 'Ivy Smithson', 'Ben Okoro', 'Eli Brown', 'Fay Chen', 'Hana Ito', 'Anna Smith', 'Jon Alder']);
});
