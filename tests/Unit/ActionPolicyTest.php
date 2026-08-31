<?php

declare(strict_types=1);

use Datawell\Actions\LinkAction;
use Datawell\Actions\ServerAction;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\Confirmation;
use Datawell\Tests\Fixtures\Models\Document;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Sources\DocumentSignatures;

it('defaults confirmation to whenDelegated', function (): void {
    expect(ServerAction::make('x')->effectiveConfirmation())->toBe(Confirmation::WhenDelegated);
});

it('lets authors relax to never for trivial actions', function (): void {
    expect(ServerAction::make('x')->confirmation(Confirmation::Never)->effectiveConfirmation())->toBe(Confirmation::Never);
});

it('floors destructive actions at always on every channel', function (): void {
    $action = ServerAction::make('x')->destructive()->confirmation(Confirmation::Never);

    expect($action->effectiveConfirmation())->toBe(Confirmation::Always)
        ->and($action->getDeclaredConfirmation())->toBe(Confirmation::Never);
});

it('floors queryScope targets at always', function (): void {
    expect(ServerAction::make('x')->targets(ActionTarget::Many, ActionTarget::QueryScope)->effectiveConfirmation())
        ->toBe(Confirmation::Always);
});

it('delegates per-row authorization to the policy via can()', function (): void {
    $owner = $this->viewer();
    $stranger = $this->privilegedViewer();
    $signature = new Signature(['document_id' => 1]);
    $signature->setRelation('document', new Document(['owner_id' => $owner->id]));

    $void = ServerAction::make('void_signature')->can('void');

    expect($void->authorizes($owner, $signature))->toBeTrue()
        ->and($void->authorizes($stranger, $signature))->toBeFalse();
});

it('accepts a closure predicate and defaults to allowed', function (): void {
    $closure = LinkAction::make('x')->authorize(fn ($user, $row): bool => $row === 'yes');

    expect($closure->authorizes($this->viewer(), 'yes'))->toBeTrue()
        ->and($closure->authorizes($this->viewer(), 'no'))->toBeFalse()
        ->and(LinkAction::make('y')->authorizes($this->viewer(), 'anything'))->toBeTrue();
});

it('resolves link urls and client payloads per row', function (): void {
    $signature = new Signature(['document_id' => 7]);
    $signature->id = 88;

    $source = new DocumentSignatures;
    $actions = $source->definition()->actions();

    expect($actions['edit']->urlFor($signature))->toBe('/documents/7/signatures/88/edit')
        ->and($actions['preview']->payloadFor($signature))->toBe(['signature_id' => 88])
        ->and($source->representation()->urlFor($signature))->toBe('/documents/7/signatures/88');
});
