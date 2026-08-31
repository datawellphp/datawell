<?php

declare(strict_types=1);

use Datawell\Executor;
use Datawell\Parameter;
use Datawell\Registry;
use Datawell\Tests\Fixtures\Sources\People;

beforeEach(function (): void {
    $this->seedDatabase();
});

it('looks an entity up through the scoped query and returns its representation', function (): void {
    $executor = app(Executor::class);

    expect($executor->lookup('people', 1, $this->viewer())?->toArray())->toBe(['id' => 1, 'label' => 'Anna Smith', 'url' => '/people/1'])
        ->and($executor->lookup('people', 11, $this->viewer()))->toBeNull()
        ->and($executor->lookup('people', 999, $this->viewer()))->toBeNull();
});

it('closes the IDOR hole: a parameter value must exist in the caller\'s scoped view of its source', function (): void {
    app(Registry::class)->register(new class extends People
    {
        public function key(): string
        {
            return 'mentees';
        }

        public function parameters(): array
        {
            return [Parameter::make('mentor_id')->required()->from('people')->rules(['integer'])];
        }
    });

    $validate = fn (int $id) => app(Executor::class)->validate(['source' => 'mentees', 'parameters' => ['mentor_id' => $id]], $this->viewer())->errors;

    expect($validate(1))->toBe([])
        ->and($validate(11))->toBe(['parameters.mentor_id' => ['Invalid mentor_id.']])
        ->and($validate(999))->toBe(['parameters.mentor_id' => ['Invalid mentor_id.']]);
});
