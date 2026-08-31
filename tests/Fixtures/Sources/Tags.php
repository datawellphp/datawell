<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Sources;

use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Fields\TextField;
use Datawell\Params;
use Datawell\Representation;
use Datawell\Tests\Fixtures\Models\Tag;
use Illuminate\Contracts\Database\Query\Builder;

#[Model(Tag::class)]
class Tags extends DataSource
{
    public function key(): string
    {
        return 'tags';
    }

    public function description(): string
    {
        return 'Labels that can be attached to signature requests.';
    }

    public function representation(): Representation
    {
        // No URL: references to tags render as plain labels (D34).
        return Representation::make(label: 'name');
    }

    public function query(Params $params): Builder
    {
        return Tag::query();
    }

    public function fields(): array
    {
        return [
            TextField::make('name')->sortable()->filterable()->searchable(),
        ];
    }
}
