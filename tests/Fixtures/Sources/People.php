<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Sources;

use Datawell\Actions\LinkAction;
use Datawell\Actions\ServerAction;
use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Enums\ActionTarget;
use Datawell\Fields\BooleanField;
use Datawell\Fields\EnumField;
use Datawell\Fields\NumberField;
use Datawell\Fields\TextField;
use Datawell\Filters\Filter;
use Datawell\Operators\Operator;
use Datawell\Params;
use Datawell\Representation;
use Datawell\Tests\Fixtures\Enums\Role;
use Datawell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A scalar-only source over the users table: the contract-test workhorse until relations land.
 */
#[Model(User::class)]
class People extends DataSource
{
    public function key(): string
    {
        return 'people';
    }

    public function description(): string
    {
        return 'People in the workspace: name, contact email, role and activity.';
    }

    public function representation(): Representation
    {
        return Representation::make(label: 'name')->url(fn (User $user): string => "/people/{$user->id}");
    }

    public function query(Params $params): EloquentBuilder|QueryBuilder
    {
        return User::query()->where('workspace_id', 1);
    }

    public function fields(): array
    {
        return [
            TextField::make('name')->sortable()->filterable()->searchable(),
            TextField::make('email')->filterable()->searchable()->visibleWhen('view-contact-details'),
            EnumField::make('role', Role::class)->filterable()->groupable(),
            NumberField::make('age')->sortable()->filterable()->nullable(),
            BooleanField::make('active')->filterable(),
            TextField::make('notes')->nullable()->filterable(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('active')->default(Operator::Is, true),
            Filter::make('adults_only')->type('boolean')->operators([Operator::Is])
                ->apply(static function (EloquentBuilder|QueryBuilder $query, Operator $operator, mixed $value): void {
                    $value ? $query->where('age', '>=', 18) : $query->where('age', '<', 18);
                }),
        ];
    }

    public function defaultSort(): array
    {
        return ['name' => 'asc'];
    }

    public function actions(): array
    {
        return [
            LinkAction::make('edit')->url(fn (User $user): string => "/people/{$user->id}/edit"),
            ServerAction::make('deactivate')->targets(ActionTarget::Single, ActionTarget::Many)
                ->description('Deactivate this person.')
                ->authorize(fn (Authenticatable $actor, User $row): bool => $row->role !== Role::Admin->value)
                ->handle(static fn (): null => null),
            ServerAction::make('purge')->destructive()->humanOnly()->description('Erase this person.')->handle(static fn (): null => null),
        ];
    }
}
