<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Sources;

use Datawell\Actions\ClientAction;
use Datawell\Actions\LinkAction;
use Datawell\Actions\ServerAction;
use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\Grain;
use Datawell\Fields\DateTimeField;
use Datawell\Fields\EnumField;
use Datawell\Fields\NumberField;
use Datawell\Fields\RelationField;
use Datawell\Fields\TextField;
use Datawell\Options;
use Datawell\Parameter;
use Datawell\Params;
use Datawell\Representation;
use Datawell\Tests\Fixtures\Enums\SignatureStatus;
use Datawell\Tests\Fixtures\Models\Signature;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The worked example from docs/datasource-examples.md, ported as the first real source.
 */
#[Model(Signature::class)]
class DocumentSignatures extends DataSource
{
    public function key(): string
    {
        return 'document-signatures';
    }

    public function name(): string
    {
        return 'Document signatures';
    }

    public function description(): string
    {
        return 'Signature requests attached to a single document: who was asked to sign, '
            .'current status, when they were asked and when they signed.';
    }

    public function visible(Authenticatable $user): bool
    {
        return $user->can('view-signatures');
    }

    public function authorize(Authenticatable $user, Params $params): bool
    {
        return $user->can('viewSignatures', $params->get('document_id'));
    }

    public function representation(): Representation
    {
        return Representation::make(label: 'signer.name')
            ->url(fn (Signature $signature): string => "/documents/{$signature->document_id}/signatures/{$signature->id}");
    }

    public function parameters(): array
    {
        return [
            Parameter::make('document_id')
                ->required()
                ->from('documents')
                ->rules(['integer']),
        ];
    }

    public function query(Params $params): EloquentBuilder|QueryBuilder
    {
        return Signature::query()->where('document_id', $params->get('document_id'));
    }

    public function fields(): array
    {
        return [
            RelationField::make('signer', from: 'signer')
                ->filterable()->searchable()
                ->options(Options::selfFacet()),

            TextField::make('signer_email', from: 'signer.email')
                ->visibleWhen('view-contact-details'),

            EnumField::make('status', SignatureStatus::class)
                ->filterable()->groupable(),

            DateTimeField::make('requested_at')
                ->sortable()->filterable()
                ->groupable(grains: [Grain::Day, Grain::Week, Grain::Month]),

            DateTimeField::make('signed_at')
                ->label('Date signed')
                ->sortable()->filterable()->nullable(),

            RelationField::make('tags', from: 'tags')
                ->references('tags')
                ->filterable()
                ->options(Options::source('tags')),

            NumberField::make('reminders_count')
                ->sortable()->filterable(),
        ];
    }

    public function defaultSort(): array
    {
        return ['requested_at' => 'desc'];
    }

    public function actions(): array
    {
        return [
            ServerAction::make('send_reminder')
                ->targets(ActionTarget::Single, ActionTarget::Many)
                ->description('Email a reminder to the signer of this signature request.')
                ->parameters([
                    Parameter::make('message')->rules(['nullable', 'string', 'max:500']),
                ])
                ->can('remind')
                ->handle(static fn (): null => null),

            ServerAction::make('void_signature')
                ->targets(ActionTarget::Single)
                ->destructive()
                ->humanOnly()
                ->can('void')
                ->description('Void this signature request. Cannot be undone.')
                ->handle(static fn (): null => null),

            LinkAction::make('edit')
                ->url(fn (Signature $signature): string => "/documents/{$signature->document_id}/signatures/{$signature->id}/edit"),

            ClientAction::make('preview')
                ->payload(fn (Signature $signature): array => ['signature_id' => $signature->id]),
        ];
    }
}
