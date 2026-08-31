<?php

declare(strict_types=1);

use Datawell\Fields\EnumField;
use Datawell\Fields\TextField;
use Datawell\Support\Key;
use Datawell\Tests\Fixtures\Enums\Priority;
use Datawell\Tests\Fixtures\Enums\SignatureStatus;

it('derives labels from keys, headline-cased', function (string $key, string $label): void {
    expect(Key::label($key))->toBe($label);
})->with([
    ['signed_at', 'Signed At'],
    ['document_id', 'Document Id'],
    ['reminders_count', 'Reminders Count'],
    ['document-signatures', 'Document Signatures'],
    ['status', 'Status'],
]);

it('lets an override win', function (): void {
    expect(TextField::make('signed_at')->label('Date signed')->getLabel())->toBe('Date signed');
});

it('derives source keys from class names for the generator', function (string $class, string $key): void {
    expect(Key::fromClassName($class))->toBe($key);
})->with([
    ['App\Datawell\DocumentSignatures', 'document-signatures'],
    ['DocumentSignaturesSource', 'document-signatures'],
    ['App\Datawell\TagsDataSource', 'tags'],
    ['Events', 'events'],
]);

it('labels enum cases from their names, or a label() method', function (): void {
    expect(EnumField::labelOf(SignatureStatus::Pending))->toBe('Pending')
        ->and(EnumField::labelOf(Priority::InReview))->toBe('Under review');
});

it('validates key shapes', function (): void {
    expect(Key::isValidSourceKey('document-signatures'))->toBeTrue()
        ->and(Key::isValidSourceKey('Document_Signatures'))->toBeFalse()
        ->and(Key::isValidItemKey('signed_at'))->toBeTrue()
        ->and(Key::isValidItemKey('signedAt'))->toBeFalse()
        ->and(Key::isValidItemKey('_x'))->toBeFalse();
});
