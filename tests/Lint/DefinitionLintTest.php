<?php

declare(strict_types=1);

use Datawell\Actions\LinkAction;
use Datawell\Actions\ServerAction;
use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\Cardinality;
use Datawell\Exceptions\DefinitionException;
use Datawell\Fields\Field;
use Datawell\Fields\RelationField;
use Datawell\Fields\TextField;
use Datawell\Filters\Filter;
use Datawell\Lint\DefinitionLinter;
use Datawell\Lint\LintReport;
use Datawell\Operators\Operator;
use Datawell\Options;
use Datawell\Params;
use Datawell\Registry;
use Datawell\Representation;
use Datawell\Sorts\Sort;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Sources\Tags;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * @param  list<Field>  $fields
 */
function signatureSource(array $fields, array $filters = [], array $sorts = [], array $actions = [], array $defaultSort = []): DataSource
{
    return new #[Model(Signature::class)] class($fields, $filters, $sorts, $actions, $defaultSort) extends DataSource
    {
        public function __construct(
            private array $fieldList,
            private array $filterList,
            private array $sortList,
            private array $actionList,
            private array $defaultSortList,
        ) {}

        public function key(): string
        {
            return 'signatures';
        }

        public function description(): string
        {
            return 'Test source.';
        }

        public function representation(): Representation
        {
            return Representation::make('id');
        }

        public function query(Params $params): Builder
        {
            return Signature::query();
        }

        public function fields(): array
        {
            return $this->fieldList;
        }

        public function filters(): array
        {
            return $this->filterList;
        }

        public function sorts(): array
        {
            return $this->sortList;
        }

        public function actions(): array
        {
            return $this->actionList;
        }

        public function defaultSort(): array
        {
            return $this->defaultSortList;
        }
    };
}

function lint(DataSource ...$sources): LintReport
{
    $registry = (new Registry(app()))->register(new Tags, ...$sources);

    return (new DefinitionLinter)->lint($registry);
}

it('passes the fixture sources with no errors or warnings', function (): void {
    $report = (new DefinitionLinter)->lint(app(Registry::class));

    expect($report->errors)->toBe([])->and($report->warnings)->toBe([]);
});

it('rejects a to-many path marked sortable with the documented message', function (): void {
    $report = lint(signatureSource([RelationField::make('tags', from: 'tags')->sortable()]));

    expect($report->errors)->toBe([
        '[signatures] tags is a to-many path and cannot be sortable; declare an aggregate field (e.g. count) instead',
    ]);
    expect(fn () => $report->throwIfErrors())->toThrow(DefinitionException::class, 'to-many path');
});

it('rejects a to-many path marked groupable', function (): void {
    $report = lint(signatureSource([TextField::make('tag_names', from: 'tags.name')->groupable()]));

    expect($report->errors[0])->toContain('tag_names is a to-many path and cannot be groupable');
});

it('introspects cardinality along a dot path through a to-one relation', function (): void {
    $report = lint(signatureSource([TextField::make('signer_email', from: 'signer.email')->sortable()]));

    expect($report->errors)->toBe([]);
});

it('requires explicit cardinality for relation fields on model-less sources', function (): void {
    $source = new class extends Tags
    {
        public function key(): string
        {
            return 'raw-tags';
        }

        public function fields(): array
        {
            return [
                RelationField::make('owner', from: 'owner'),
                RelationField::make('labels', from: 'labels')->cardinality(Cardinality::Many),
            ];
        }
    };

    $report = lint($source);

    expect($report->errors)->toBe([
        '[raw-tags] field "owner" is a relation on a source with no #[Model]; declare ->cardinality() explicitly',
    ]);
});

it('rejects a filter that widens its field operators', function (): void {
    $report = lint(signatureSource(
        [TextField::make('note')->filterable()],
        [Filter::make('note')->operators([Operator::Contains, Operator::Gt])],
    ));

    expect($report->errors[0])->toBe('[signatures] filter "note" widens its field\'s operators with gt; filters may narrow, never widen');
});

it('rejects filters and sorts over unknown or incapable fields', function (): void {
    $report = lint(signatureSource(
        [TextField::make('note')],
        [Filter::make('missing'), Filter::make('note')],
        [Sort::make('nope')->field('missing'), Sort::make('note')],
        [],
        ['ghost' => 'sideways'],
    ));

    expect($report->errors)->toBe([
        '[signatures] filter "missing" references field "missing", which does not exist; a custom filter must declare ->apply()',
        '[signatures] filter "note" is backed by field "note", which is not filterable',
        '[signatures] sort "nope" references field "missing", which does not exist; a custom sort must declare ->apply()',
        '[signatures] sort "note" is backed by field "note", which is not sortable',
        '[signatures] defaultSort references "ghost", which is not a sort',
        '[signatures] defaultSort direction for "ghost" must be "asc" or "desc"',
    ]);
});

it('accepts a custom filter that declares type, operators and apply', function (): void {
    $report = lint(signatureSource(
        [TextField::make('note')],
        [Filter::make('duplicates_only')->type('boolean')->operators([Operator::Is])->apply(fn (): null => null)],
    ));

    expect($report->errors)->toBe([]);
});

it('rejects malformed keys', function (): void {
    $source = new class extends Tags
    {
        public function key(): string
        {
            return 'Bad Key';
        }

        public function fields(): array
        {
            return [TextField::make('camelCase')];
        }
    };

    $report = lint($source);

    expect($report->errors)->toBe([
        '[Bad Key] key "Bad Key" must be lowercase kebab-case (e.g. "document-signatures")',
        '[Bad Key] field key "camelCase" must be lowercase snake_case',
    ]);
});

it('rejects duplicate keys and unregistered references', function (): void {
    $report = lint(signatureSource([
        TextField::make('note'),
        TextField::make('note'),
        RelationField::make('signer', from: 'signer')->filterable()->options(Options::source('people')),
    ]));

    expect($report->errors)->toBe([
        '[signatures] declares field "note" more than once',
        '[signatures] field "signer" options references source "people", which is not registered',
    ]);
});

it('checks action anatomy', function (): void {
    $report = lint(signatureSource([TextField::make('note')], [], [], [
        ServerAction::make('nothing')->targets(),
        ServerAction::make('mixed')->targets(ActionTarget::Standalone, ActionTarget::Single)->handle(fn (): null => null)->description('x'),
        LinkAction::make('bulk_link')->targets(ActionTarget::QueryScope),
        ServerAction::make('quiet')->handle(fn (): null => null),
    ]));

    expect($report->errors)->toBe([
        '[signatures] action "nothing" declares no targets',
        '[signatures] action "nothing" has no handler',
        '[signatures] action "mixed" cannot combine standalone with row targets',
        '[signatures] action "bulk_link" is a link action and cannot target queryScope',
        '[signatures] link action "bulk_link" has no URL resolver',
    ]);
    expect($report->warnings)->toBe([
        '[signatures] action "nothing" has no description — it becomes an AI tool without one',
        '[signatures] action "quiet" has no description — it becomes an AI tool without one',
    ]);
});

it('warns when a source has no description', function (): void {
    $source = new class extends Tags
    {
        public function key(): string
        {
            return 'quiet';
        }

        public function description(): string
        {
            return '';
        }
    };

    $report = lint($source);

    expect($report->errors)->toBe([])
        ->and($report->warnings)->toBe(['[quiet] has no description — it is AI-facing prose, and a source without one is half-invisible to the smartest consumer']);
});
