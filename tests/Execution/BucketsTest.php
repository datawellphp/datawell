<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Datawell\Executor;
use Datawell\Tests\Fixtures\Models\User;
use Datawell\Validation\ValidationException;

beforeEach(function (): void {
    $this->seedDatabase();
    CarbonImmutable::setTestNow('2026-08-18 15:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function buckets(array $wire, ?User $user = null): array
{
    return app(Executor::class)->run(['source' => 'people', ...$wire], $user ?? test()->viewer())->toArray();
}

it('counts by an enum group, buckets keyed by refs and ordered by count', function (): void {
    $result = buckets(['groupBy' => [['key' => 'role']], 'aggregates' => [['fn' => 'count']]]);

    expect($result['buckets'])->toBe([
        ['role' => ['id' => 'viewer', 'label' => 'Viewer'], 'count' => 5],
        ['role' => ['id' => 'admin', 'label' => 'Admin'], 'count' => 2],
        ['role' => ['id' => 'editor', 'label' => 'Editor'], 'count' => 1],
    ])->and($result['meta'])->toBe(['count' => 3, 'truncated' => false])
        ->and($result['applied']['filters']['conditions'][0]['filter'])->toBe('active')
        ->and(array_key_exists('page', $result['applied']))->toBeFalse();
});

it('computes measures over permitted aggregates', function (): void {
    $result = buckets([
        'filters' => ['conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => true], ['filter' => 'adults_only', 'operator' => 'is', 'value' => true]]],
        'groupBy' => [['key' => 'role']],
        'aggregates' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'age'], ['fn' => 'max', 'field' => 'age'], ['fn' => 'avg', 'field' => 'age']],
    ]);

    expect($result['buckets'][0])->toBe(['role' => ['id' => 'viewer', 'label' => 'Viewer'], 'count' => 4, 'sum_age' => 128, 'max_age' => 35, 'avg_age' => 32.0])
        ->and($result['buckets'][1])->toBe(['role' => ['id' => 'admin', 'label' => 'Admin'], 'count' => 2, 'sum_age' => 101, 'max_age' => 60, 'avg_age' => 50.5]);
});

it('buckets wall dates by grain on every driver, ordered chronologically', function (): void {
    $result = buckets(['groupBy' => [['key' => 'joined_on', 'grain' => 'month']], 'aggregates' => [['fn' => 'count']]]);

    expect(array_map(fn ($b) => [$b['joined_on']['id'], $b['joined_on']['label'], $b['count']], $result['buckets']))->toBe([
        ['2025-12-01', 'Dec 2025', 1],
        ['2026-03-01', 'Mar 2026', 2],
        ['2026-07-01', 'Jul 2026', 1],
        ['2026-08-01', 'Aug 2026', 4],
    ]);

    $years = buckets(['groupBy' => [['key' => 'joined_on', 'grain' => 'year']], 'aggregates' => [['fn' => 'count']]]);
    expect(array_column(array_column($years['buckets'], 'joined_on'), 'id'))->toBe(['2025-01-01', '2026-01-01']);
});

it('buckets instants by grain for a UTC user on sqlite', function (): void {
    $result = buckets(['groupBy' => [['key' => 'last_seen_at', 'grain' => 'day']], 'aggregates' => [['fn' => 'count']]], User::fake(9));

    expect(array_map(fn ($b) => [$b['last_seen_at']['id'], $b['count']], $result['buckets']))->toBe([
        ['2026-03-08', 1], ['2026-03-09', 1], ['2026-07-20', 1], ['2026-08-10', 1], ['2026-08-18', 2], ['2026-08-19', 2],
    ]);

    $weeks = buckets(['groupBy' => [['key' => 'last_seen_at', 'grain' => 'week']], 'aggregates' => [['fn' => 'count']]], User::fake(9));
    expect(array_map(fn ($b) => [$b['last_seen_at']['id'], $b['last_seen_at']['label']], $weeks['buckets']))->toBe([
        ['2026-03-02', 'Week of 2 Mar 2026'], ['2026-03-09', 'Week of 9 Mar 2026'], ['2026-07-20', 'Week of 20 Jul 2026'],
        ['2026-08-10', 'Week of 10 Aug 2026'], ['2026-08-17', 'Week of 17 Aug 2026'],
    ]);
});

it('refuses to bucket instants in a non-UTC timezone on sqlite, explicitly (D51)', function (): void {
    $message = 'Field "last_seen_at" cannot be bucketed by day in America/New_York on sqlite: this driver cannot convert timezones, so date-time grains require a UTC effective timezone here.';

    expect(fn () => buckets(['groupBy' => [['key' => 'last_seen_at', 'grain' => 'day']], 'aggregates' => [['fn' => 'count']]]))
        ->toThrow(ValidationException::class, $message);

    $report = app(Executor::class)->validate(['source' => 'people', 'groupBy' => [['key' => 'last_seen_at', 'grain' => 'day']], 'aggregates' => [['fn' => 'count']]], $this->viewer());
    expect($report->errors)->toBe(['groupBy.0.grain' => [$message]]);

    // Wall dates never need conversion, so they bucket fine for the same user.
    expect(buckets(['groupBy' => [['key' => 'joined_on', 'grain' => 'month']], 'aggregates' => [['fn' => 'count']]])['meta']['count'])->toBe(4);
});

it('caps buckets with an explicit truncated flag', function (): void {
    config()->set('datawell.buckets.max', 2);

    $result = buckets(['groupBy' => [['key' => 'role']], 'aggregates' => [['fn' => 'count']]]);

    expect($result['meta'])->toBe(['count' => 2, 'truncated' => true])
        ->and(count($result['buckets']))->toBe(2);
});

it('rejects sorts, select and page on grouped requests', function (): void {
    $errors = app(Executor::class)->validate([
        'source' => 'people',
        'groupBy' => [['key' => 'role']],
        'aggregates' => [['fn' => 'count']],
        'sorts' => [['key' => 'name']],
        'select' => ['name'],
        'page' => ['size' => 5],
    ], $this->viewer())->errors;

    expect(array_keys($errors))->toBe(['sorts', 'select', 'page']);
});

it('runs an aggregate without groups as a single bucket', function (): void {
    $result = buckets(['aggregates' => [['fn' => 'count'], ['fn' => 'min', 'field' => 'age']]]);

    expect($result['buckets'])->toBe([['count' => 8, 'min_age' => 17]]);
});
