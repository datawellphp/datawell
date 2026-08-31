<?php

declare(strict_types=1);

use Datawell\Exceptions\SourceNotFoundException;
use Datawell\Execution\Channel;
use Datawell\Executor;
use Datawell\Fields\TextField;
use Datawell\Params;
use Datawell\Registry;
use Datawell\Tests\Fixtures\Sources\DocumentSignatures;
use Datawell\Validation\ValidationException;
use Illuminate\Contracts\Auth\Authenticatable;

function validate(array $wire, ?Authenticatable $user = null, Channel $channel = Channel::Direct): array
{
    return app(Executor::class)->validate(['source' => 'document-signatures', ...$wire], $user ?? test()->viewer(), $channel)->errors;
}

it('passes the worked example request', function (): void {
    $report = app(Executor::class)->validate([
        'source' => 'document-signatures',
        'parameters' => ['document_id' => 123],
        'search' => 'smith',
        'filters' => [
            'boolean' => 'or',
            'conditions' => [
                ['filter' => 'status', 'operator' => 'in', 'value' => ['pending']],
                ['boolean' => 'and', 'conditions' => [
                    ['filter' => 'signed_at', 'operator' => 'isEmpty'],
                    ['filter' => 'requested_at', 'operator' => 'after', 'value' => ['relative' => 'ago', 'n' => 30, 'unit' => 'days']],
                ]],
            ],
        ],
        'sorts' => [['key' => 'requested_at', 'direction' => 'desc']],
        'page' => ['number' => 1, 'size' => 25],
    ], $this->viewer());

    expect($report->passes())->toBeTrue()->and($report->errors)->toBe([]);
});

it('rejects the documented failure cases', function (array $wire, string $path, string $message): void {
    $errors = validate(['parameters' => ['document_id' => 123], ...$wire]);

    expect($errors)->toHaveKey($path)->and($errors[$path])->toBe([$message]);
})->with([
    'oracle: hidden filter is unknown' => [
        ['filters' => ['conditions' => [['filter' => 'signer_email', 'operator' => 'contains', 'value' => '@rival.com']]]],
        'filters.conditions.0.filter', 'Unknown filter "signer_email".',
    ],
    'nonexistent filter reads identically' => [
        ['filters' => ['conditions' => [['filter' => 'nope', 'operator' => 'contains', 'value' => 'x']]]],
        'filters.conditions.0.filter', 'Unknown filter "nope".',
    ],
    'type violation' => [
        ['filters' => ['conditions' => [['filter' => 'reminders_count', 'operator' => 'contains', 'value' => '2']]]],
        'filters.conditions.0.operator', 'Operator "contains" is not valid for filter "reminders_count".',
    ],
    'shape violation' => [
        ['filters' => ['conditions' => [['filter' => 'requested_at', 'operator' => 'between', 'value' => '2026-08-01']]]],
        'filters.conditions.0.value', 'Operator "between" expects { from, to }.',
    ],
    'depth violation' => [
        ['filters' => ['conditions' => [['boolean' => 'or', 'conditions' => [['boolean' => 'and', 'conditions' => [['filter' => 'status', 'operator' => 'in', 'value' => ['pending']]]]]]]]],
        'filters', 'Filter groups may nest at most two levels.',
    ],
    'value on a valueless operator' => [
        ['filters' => ['conditions' => [['filter' => 'signed_at', 'operator' => 'isEmpty', 'value' => true]]]],
        'filters.conditions.0.value', 'Operator "isEmpty" takes no value.',
    ],
    'empty list' => [
        ['filters' => ['conditions' => [['filter' => 'tags', 'operator' => 'hasAny', 'value' => []]]]],
        'filters.conditions.0.value', 'Operator "hasAny" expects a non-empty list of values.',
    ],
    'number expected' => [
        ['filters' => ['conditions' => [['filter' => 'reminders_count', 'operator' => 'gt', 'value' => 'two']]]],
        'filters.conditions.0.value', 'Operator "gt" expects a number.',
    ],
    'hidden sort is unknown' => [['sorts' => [['key' => 'signer_email']]], 'sorts.0.key', 'Unknown sort "signer_email".'],
    'hidden select is unknown' => [['select' => ['signer_email']], 'select.0', 'Unknown field "signer_email".'],
    'not groupable' => [['groupBy' => [['key' => 'signed_at']], 'aggregates' => [['fn' => 'count']]], 'groupBy.0.key', 'Field "signed_at" is not groupable.'],
    'grain not offered' => [['groupBy' => [['key' => 'requested_at', 'grain' => 'year']], 'aggregates' => [['fn' => 'count']]], 'groupBy.0.grain', 'Grain "year" is not available for "requested_at"; expected one of day, week, month.'],
    'grain on non-date' => [['groupBy' => [['key' => 'status', 'grain' => 'day']], 'aggregates' => [['fn' => 'count']]], 'groupBy.0.grain', 'Field "status" cannot be bucketed by grain.'],
    'group without aggregate' => [['groupBy' => [['key' => 'status']]], 'aggregates', 'A grouped request needs at least one aggregate.'],
    'aggregate needs field' => [['aggregates' => [['fn' => 'sum']]], 'aggregates.0.field', 'Aggregate "sum" needs a field.'],
    'aggregate not permitted' => [['aggregates' => [['fn' => 'sum', 'field' => 'reminders_count']]], 'aggregates.0.fn', 'Aggregate "sum" is not available for "reminders_count".'],
    'count takes no field' => [['aggregates' => [['fn' => 'count', 'field' => 'status']]], 'aggregates.0.field', 'The count aggregate takes no field.'],
    'page ceiling' => [['page' => ['size' => 101]], 'page.size', 'Page size may not exceed 100.'],
]);

it('accepts every documented value form', function (array $leaf): void {
    expect(validate(['parameters' => ['document_id' => 1], 'filters' => ['conditions' => [$leaf]]]))->toBe([]);
})->with([
    'status in' => [['filter' => 'status', 'operator' => 'in', 'value' => ['pending', 'signed']]],
    'date on' => [['filter' => 'signed_at', 'operator' => 'on', 'value' => '2026-08-18']],
    'instant after' => [['filter' => 'requested_at', 'operator' => 'after', 'value' => '2026-08-01T14:03:00Z']],
    'relative point' => [['filter' => 'requested_at', 'operator' => 'before', 'value' => ['relative' => 'ago', 'n' => 7, 'unit' => 'days']]],
    'today' => [['filter' => 'requested_at', 'operator' => 'on', 'value' => ['relative' => 'today']]],
    'absolute range' => [['filter' => 'requested_at', 'operator' => 'between', 'value' => ['from' => '2026-08-01', 'to' => '2026-08-31']]],
    'relative period' => [['filter' => 'requested_at', 'operator' => 'between', 'value' => ['relative' => 'last', 'n' => 30, 'unit' => 'days']]],
    'this month' => [['filter' => 'requested_at', 'operator' => 'between', 'value' => ['relative' => 'this', 'unit' => 'month']]],
    'number range' => [['filter' => 'reminders_count', 'operator' => 'between', 'value' => ['from' => 1, 'to' => 3]]],
    'has all' => [['filter' => 'tags', 'operator' => 'hasAll', 'value' => [14, 3]]],
    'is empty' => [['filter' => 'tags', 'operator' => 'isEmpty']],
]);

it('lets a privileged user filter and sort on the field hidden from others', function (): void {
    app(Registry::class)->register(new class extends DocumentSignatures
    {
        public function key(): string
        {
            return 'contact-signatures';
        }

        public function fields(): array
        {
            return [
                ...parent::fields(),
                TextField::make('signer_phone', from: 'signer.phone')
                    ->filterable()->sortable()
                    ->visibleWhen('view-contact-details'),
            ];
        }
    });

    $wire = fn (Authenticatable $user) => app(Executor::class)->validate([
        'source' => 'contact-signatures',
        'parameters' => ['document_id' => 1],
        'filters' => ['conditions' => [['filter' => 'signer_phone', 'operator' => 'contains', 'value' => '555']]],
        'sorts' => [['key' => 'signer_phone']],
    ], $user)->errors;

    expect($wire($this->privilegedViewer()))->toBe([])
        ->and($wire($this->viewer()))->toBe([
            'filters.conditions.0.filter' => ['Unknown filter "signer_phone".'],
            'sorts.0.key' => ['Unknown sort "signer_phone".'],
        ]);
});

it('validates parameters with Laravel rules and fills defaults', function (): void {
    expect(validate([]))->toBe(['parameters.document_id' => ['The document id field is required.']])
        ->and(validate(['parameters' => ['document_id' => 'abc']]))->toBe(['parameters.document_id' => ['The document id field must be an integer.']])
        ->and(validate(['parameters' => ['document_id' => 1, 'extra' => 2]]))->toBe(['parameters.extra' => ['Unknown parameter "extra".']]);
});

it('masks a failed authorize() as an invalid parameter', function (): void {
    $gated = new class extends DocumentSignatures
    {
        public function key(): string
        {
            return 'gated-signatures';
        }

        public function authorize(Authenticatable $user, Params $params): bool
        {
            return $params->get('document_id') !== 500;
        }
    };
    app(Registry::class)->register($gated);

    $open = app(Executor::class)->validate(['source' => 'gated-signatures', 'parameters' => ['document_id' => 123]], $this->viewer());
    $shut = app(Executor::class)->validate(['source' => 'gated-signatures', 'parameters' => ['document_id' => 500]], $this->viewer());

    expect($open->passes())->toBeTrue()
        ->and($shut->errors)->toBe(['parameters.document_id' => ['Invalid document_id.']]);
});

it('applies the stricter page ceiling on delegated channels', function (): void {
    $wire = ['parameters' => ['document_id' => 1], 'page' => ['size' => 60]];

    expect(validate($wire, channel: Channel::Direct))->toBe([])
        ->and(validate($wire, channel: Channel::DelegatedInteractive))->toBe(['page.size' => ['Page size may not exceed 50.']]);
});

it('treats a hidden source as not found', function (): void {
    expect(fn () => app(Executor::class)->validate(['source' => 'document-signatures'], $this->outsider()))
        ->toThrow(SourceNotFoundException::class, 'Unknown data source "document-signatures".');
});

it('offers throwIfFails for callers that want an exception', function (): void {
    $report = app(Executor::class)->validate(['source' => 'document-signatures'], $this->viewer());

    expect($report->fails())->toBeTrue()
        ->and(fn () => $report->throwIfFails())->toThrow(ValidationException::class, 'The document id field is required.');
});
