<?php

declare(strict_types=1);

namespace Datawell\Schema;

use Datawell\Actions\Action;
use Datawell\DataSource;
use Datawell\Fields\Field;
use Datawell\Filters\Filter;
use Datawell\Parameter;
use Datawell\Sorts\Sort;
use Datawell\Timezone\TimezoneResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds a source's Schema for one user (D18, D32, D47): every label resolved,
 * every hidden field/filter/sort/action absent, the effective timezone advertised.
 */
class Describer
{
    public function __construct(protected TimezoneResolver $timezones) {}

    public function describe(DataSource $source, Authenticatable $user): Schema
    {
        $definition = $source->definition();
        $visible = static fn (Field|Filter|Sort|Action $item): bool => $item->isVisibleTo($user);
        $sorts = array_filter($definition->sorts(), $visible);

        return new Schema([
            'source' => [
                'key' => $source->key(),
                'name' => $source->name(),
                'description' => $source->description(),
                'timezone' => $this->timezones->resolve($user),
                'representation' => $source->representation()->describe($this->defaultIdOf($definition->model())),
            ],
            'parameters' => array_values(array_map(
                static fn (Parameter $parameter): array => $parameter->describe(),
                $definition->parameters(),
            )),
            'fields' => $this->describeAll(array_filter($definition->fields(), $visible)),
            'filters' => $this->describeAll(array_filter($definition->filters(), $visible)),
            'sorts' => $this->describeAll($sorts),
            'defaultSort' => $this->defaultSort($source, array_keys($sorts)),
            'actions' => $this->describeAll(array_filter($definition->actions(), $visible)),
        ]);
    }

    /**
     * @param  array<string, Field|Filter|Sort|Action>  $items
     * @return list<array<string, mixed>>
     */
    protected function describeAll(array $items): array
    {
        return array_values(array_map(
            static fn (Field|Filter|Sort|Action $item): array => $item->describe(),
            $items,
        ));
    }

    /**
     * @param  list<string>  $visibleSortKeys
     * @return list<array{key: string, direction: string}>
     */
    protected function defaultSort(DataSource $source, array $visibleSortKeys): array
    {
        $defaults = [];

        foreach ($source->defaultSort() as $key => $direction) {
            if (in_array($key, $visibleSortKeys, true)) {
                $defaults[] = ['key' => $key, 'direction' => $direction];
            }
        }

        return $defaults;
    }

    /**
     * @param  class-string<Model>|null  $model
     */
    protected function defaultIdOf(?string $model): string
    {
        return $model === null ? 'id' : (new $model)->getKeyName();
    }
}
