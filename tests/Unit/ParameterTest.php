<?php

declare(strict_types=1);

use Datawell\Actions\ServerAction;
use Datawell\Parameter;
use Datawell\Tests\Fixtures\Sources\DocumentSignatures;

it('publishes provenance and switches the type to relation', function (): void {
    $parameter = Parameter::make('document_id')->required()->rules(['integer'])->from('documents', ['owner_id' => 'user_id']);

    expect($parameter->describe())->toBe([
        'key' => 'document_id', 'label' => 'Document Id', 'type' => 'relation', 'required' => true,
        'rules' => ['integer'], 'from' => ['source' => 'documents', 'parameters' => ['owner_id' => 'user_id']],
    ]);
});

it('publishes defaults and descriptions when declared', function (): void {
    expect(Parameter::make('limit')->type('number')->default(10)->description('How many.')->describe())->toBe([
        'key' => 'limit', 'label' => 'Limit', 'type' => 'number', 'required' => false, 'rules' => [],
        'default' => 10, 'description' => 'How many.',
    ]);
});

it('aggregates rules on the parent', function (): void {
    expect((new DocumentSignatures)->rules())->toBe(['document_id' => ['integer']]);

    $action = ServerAction::make('x')->parameters([
        Parameter::make('message')->rules(['nullable', 'string', 'max:500']),
        Parameter::make('cc')->rules(['array']),
    ]);

    expect($action->rules())->toBe(['message' => ['nullable', 'string', 'max:500'], 'cc' => ['array']]);
});
