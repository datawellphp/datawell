<?php

declare(strict_types=1);

namespace Datawell\Console;

use Datawell\Support\Key;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generates a data source with its key stamped literally into the class (D30) and,
 * when a model is given, the #[Model] attribute (D46).
 */
#[AsCommand(name: 'make:datawell')]
class MakeDatawellCommand extends GeneratorCommand
{
    protected $name = 'make:datawell';

    protected $description = 'Create a new Datawell data source';

    protected $type = 'Data source';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/datawell.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Datawell';
    }

    protected function buildClass($name): string
    {
        $class = class_basename($name);
        $model = $this->option('model');
        $model = is_string($model) && $model !== '' ? $this->qualifyModel($model) : null;

        $imports = [
            'Datawell\DataSource',
            'Datawell\Fields\Field',
            'Datawell\Params',
            'Datawell\Representation',
            'Illuminate\Contracts\Database\Query\Builder',
        ];

        if ($model !== null) {
            $imports[] = 'Datawell\Attributes\Model';
            $imports[] = $model;
        }

        sort($imports);

        $replacements = [
            '{{ key }}' => Key::fromClassName($class),
            '{{ imports }}' => implode("\n", array_map(static fn (string $import): string => "use {$import};", $imports)),
            '{{ attribute }}' => $model === null ? '' : sprintf("#[Model(%s::class)]\n", class_basename($model)),
            '{{ query }}' => $model === null
                ? "// return Model::query()->where(...);\n        throw new \\LogicException('Define the base query for this source.');"
                : sprintf('return %s::query();', class_basename($model)),
        ];

        return Str::replace(array_keys($replacements), array_values($replacements), parent::buildClass($name));
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The Eloquent model backing the source'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the data source already exists'],
        ];
    }
}
