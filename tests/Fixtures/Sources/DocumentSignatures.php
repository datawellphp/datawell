<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Sources;

use Datawell\Actions\ActionContext;
use Datawell\Actions\ClientAction;
use Datawell\Actions\LinkAction;
use Datawell\Actions\ServerAction;
use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\AggregateType;
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
use Datawell\Tests\Fixtures\Models\Reminder;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Support\MailboxRejectedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

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
                ->sortable()->filterable()->searchable()->groupable()
                ->options(Options::selfFacet()),

            TextField::make('signer_email', from: 'signer.email')
                ->sortable()->filterable()->searchable()->nullable()
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
                ->countOf('reminders')
                ->sortable()->filterable()->groupable()
                ->aggregates(AggregateType::Sum, AggregateType::Avg),

            DateTimeField::make('last_reminder_at')
                ->maxOf('reminders', 'sent_at')
                ->sortable()->filterable()
                ->groupable(grains: [Grain::Day]),
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
                ->handle(static function (Collection $rows, Params $input, ActionContext $context): void {
                    $sent = 0;

                    foreach ($rows as $signature) {
                        if ($signature->signer_id === null) {
                            $context->fail($signature, 'No signer to remind.');

                            continue;
                        }

                        if ($signature->signer?->email === 'cara@rival.com') {
                            throw new MailboxRejectedException('Mailbox rejected the address');
                        }

                        Reminder::query()->create(['signature_id' => $signature->id, 'sent_at' => $context->now->format('Y-m-d H:i:s')]);
                        $sent++;
                    }

                    $context->message(sprintf('Reminder sent to %d signer%s.', $sent, $sent === 1 ? '' : 's'));
                }),

            ServerAction::make('decline_stale')
                ->targets(ActionTarget::Many, ActionTarget::QueryScope)
                ->description('Decline pending signature requests that will not be signed.')
                ->authorize(static fn (Authenticatable $user, mixed $signature): bool => $signature instanceof Signature && $signature->status === 'pending')
                ->authorizeQuery(static fn (EloquentBuilder|QueryBuilder $query) => $query->where('status', 'pending'))
                ->transactional()
                ->handle(static function (Collection $rows, Params $input, ActionContext $context): void {
                    foreach ($rows as $signature) {
                        $signature->update(['status' => 'declined']);
                    }
                }),

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
