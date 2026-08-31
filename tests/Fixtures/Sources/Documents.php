<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Sources;

use Datawell\Actions\LinkAction;
use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Fields\DateTimeField;
use Datawell\Fields\NumberField;
use Datawell\Fields\RelationField;
use Datawell\Fields\TextField;
use Datawell\Filters\Filter;
use Datawell\Operators\Operator;
use Datawell\Params;
use Datawell\Representation;
use Datawell\Tests\Fixtures\Models\Document;
use Illuminate\Contracts\Database\Query\Builder;

#[Model(Document::class)]
class Documents extends DataSource
{
    public function key(): string
    {
        return 'documents';
    }

    public function description(): string
    {
        return 'Documents the user can see: their own and those shared with them.';
    }

    public function representation(): Representation
    {
        return Representation::make(label: 'title')
            ->url(fn (Document $document): string => "/documents/{$document->id}");
    }

    public function query(Params $params): Builder
    {
        return Document::query();
    }

    public function fields(): array
    {
        return [
            TextField::make('title')->sortable()->filterable()->searchable(),
            RelationField::make('owner', from: 'owner')->filterable(),
            NumberField::make('signatures_count')->sortable()->filterable(),
            DateTimeField::make('created_at')->sortable()->filterable(),
            DateTimeField::make('archived_at')->nullable()->filterable(),
        ];
    }

    public function filters(): array
    {
        return [
            // A mode toggle modelled as a filter with a default (D35): archived documents are hidden at rest.
            Filter::make('archived_at')
                ->operators([Operator::IsEmpty, Operator::IsNotEmpty])
                ->default(Operator::IsEmpty),
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at' => 'desc'];
    }

    public function actions(): array
    {
        return [
            LinkAction::make('open')->url(fn (Document $document): string => "/documents/{$document->id}"),
        ];
    }
}
