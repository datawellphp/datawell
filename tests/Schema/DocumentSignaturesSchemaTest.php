<?php

declare(strict_types=1);

use Datawell\Registry;

it('describes document signatures for a viewer without contact details', function (): void {
    $schema = app(Registry::class)->find('document-signatures')->describe($this->viewer());

    expect($schema->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->toMatchSnapshot();
});

it('describes document signatures for a viewer with contact details', function (): void {
    $schema = app(Registry::class)->find('document-signatures')->describe($this->privilegedViewer());

    expect($schema->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->toMatchSnapshot();
});

it('matches the worked example shape from the design pack', function (): void {
    $schema = app(Registry::class)->find('document-signatures')->describe($this->viewer())->toArray();

    expect($schema['source'])->toBe([
        'key' => 'document-signatures',
        'name' => 'Document signatures',
        'description' => 'Signature requests attached to a single document: who was asked to sign, '
            .'current status, when they were asked and when they signed.',
        'timezone' => 'America/New_York',
        'representation' => ['id' => 'id', 'label' => 'signer.name'],
    ]);

    expect($schema['parameters'])->toBe([[
        'key' => 'document_id', 'label' => 'Document Id', 'type' => 'relation', 'required' => true,
        'rules' => ['integer'], 'from' => ['source' => 'documents'],
    ]]);

    expect(array_column($schema['fields'], 'key'))
        ->toBe(['signer', 'status', 'requested_at', 'signed_at', 'tags', 'reminders_count', 'last_reminder_at']);

    expect(array_column($schema['filters'], 'label', 'key')['signed_at'])->toBe('Date signed')
        ->and(array_column($schema['sorts'], 'label', 'key')['signed_at'])->toBe('Date signed');

    $filters = array_column($schema['filters'], 'operators', 'key');
    expect($filters['status'])->toBe(['in', 'notIn'])
        ->and($filters['signed_at'])->toBe(['on', 'before', 'after', 'between', 'isEmpty', 'isNotEmpty'])
        ->and($filters['requested_at'])->toBe(['on', 'before', 'after', 'between'])
        ->and($filters['tags'])->toBe(['hasAny', 'hasAll', 'hasNone', 'isEmpty', 'isNotEmpty'])
        ->and($filters['reminders_count'])->toBe(['equals', 'notEquals', 'gt', 'gte', 'lt', 'lte', 'between'])
        ->and($filters['signer'])->toBe(['in', 'notIn']);

    $actions = array_column($schema['actions'], null, 'key');
    expect($actions['send_reminder'])->toMatchArray(['kind' => 'server', 'targets' => ['single', 'many'], 'destructive' => false, 'confirmation' => 'whenDelegated'])
        ->and($actions['void_signature'])->toMatchArray(['kind' => 'server', 'targets' => ['single'], 'destructive' => true, 'confirmation' => 'always', 'humanOnly' => true])
        ->and($actions['edit'])->toBe(['key' => 'edit', 'label' => 'Edit', 'kind' => 'link', 'targets' => ['single']])
        ->and($actions['preview'])->toBe(['key' => 'preview', 'label' => 'Preview', 'kind' => 'client', 'targets' => ['single']]);
});
