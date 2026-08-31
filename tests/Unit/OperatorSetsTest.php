<?php

declare(strict_types=1);

use Datawell\Enums\Cardinality;
use Datawell\Enums\ValueShape;
use Datawell\Fields\BooleanField;
use Datawell\Fields\DateField;
use Datawell\Fields\DateTimeField;
use Datawell\Fields\EnumField;
use Datawell\Fields\Field;
use Datawell\Fields\MoneyField;
use Datawell\Fields\NumberField;
use Datawell\Fields\RelationField;
use Datawell\Fields\TextField;
use Datawell\Operators\Operator;
use Datawell\Tests\Fixtures\Enums\SignatureStatus;

$values = fn (array $operators): array => array_map(fn (Operator $operator) => $operator->value, $operators);

it('pins the canonical single-cardinality operator sets', function (Field $field, array $expected) use ($values): void {
    expect($values($field->operators()))->toBe($expected);
})->with([
    'text' => [TextField::make('x'), ['equals', 'notEquals', 'contains', 'startsWith', 'endsWith']],
    'number' => [NumberField::make('x'), ['equals', 'notEquals', 'gt', 'gte', 'lt', 'lte', 'between']],
    'money' => [MoneyField::make('x'), ['equals', 'notEquals', 'gt', 'gte', 'lt', 'lte', 'between']],
    'boolean' => [BooleanField::make('x'), ['is']],
    'date' => [DateField::make('x'), ['on', 'before', 'after', 'between']],
    'dateTime' => [DateTimeField::make('x'), ['on', 'before', 'after', 'between']],
    'enum' => [EnumField::make('x', SignatureStatus::class), ['in', 'notIn']],
    'relation' => [RelationField::make('x'), ['in', 'notIn']],
]);

it('appends the null operators to nullable single fields', function () use ($values): void {
    expect($values(NumberField::make('x')->nullable()->operators()))
        ->toBe(['equals', 'notEquals', 'gt', 'gte', 'lt', 'lte', 'between', 'isEmpty', 'isNotEmpty']);
});

it('gives every many-cardinality field the same set regardless of type', function () use ($values): void {
    $many = ['hasAny', 'hasAll', 'hasNone', 'isEmpty', 'isNotEmpty'];

    expect($values(RelationField::make('tags')->cardinality(Cardinality::Many)->operators()))->toBe($many)
        ->and($values(EnumField::make('flags', SignatureStatus::class)->cardinality(Cardinality::Many)->operators()))->toBe($many);
});

it('declares a value shape for every operator', function (): void {
    foreach (Operator::cases() as $operator) {
        expect($operator->shape())->toBeInstanceOf(ValueShape::class);
    }

    expect(Operator::Between->shape())->toBe(ValueShape::Range)
        ->and(Operator::In->shape())->toBe(ValueShape::List)
        ->and(Operator::IsEmpty->shape())->toBe(ValueShape::None)
        ->and(Operator::Contains->shape())->toBe(ValueShape::Scalar);
});

it('only exposes grains on date fields', function (): void {
    expect(method_exists(BooleanField::class, 'getGrains'))->toBeFalse()
        ->and(DateTimeField::make('x')->groupable(grains: ['day', 'month'])->describe()['grains'])->toBe(['day', 'month']);
});
