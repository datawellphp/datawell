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
 * when a model is given, the #[Model] attribute (D46). Sources live under App\Datawell.
 */
#[AsCommand(name: 'make:datawell')]
class MakeDatawellCommand extends GeneratorCommand
{
    protected $name = 'make:datawell';

    protected $description = 'Create a new Datawell data source';

    protected $type = 'Data source';

    public function handle(): ?bool
    {
        $this->input->setArgument('name', $this->placeUnderDatawellNamespace($this->nameArgument()));

        if (parent::handle() === false) {
            return false;
        }

        $path = $this->getPath($this->qualifyClass($this->nameArgument()));

        file_put_contents($path, $this->fillPlaceholders((string) file_get_contents($path)));

        return null;
    }

    protected function getStub(): string
    {
        return __DIR__.'/stubs/datawell.stub';
    }

    protected function nameArgument(): string
    {
        $name = $this->argument('name');

        return is_string($name) ? trim(str_replace('/', '\\', $name), '\\') : '';
    }

    /**
     * Unqualified names land in App\Datawell; a fully qualified name is honoured as given.
     */
    protected function placeUnderDatawellNamespace(string $name): string
    {
        $root = trim($this->rootNamespace(), '\\');

        return Str::startsWith($name, $root.'\\') ? $name : $root.'\\Datawell\\'.$name;
    }

    protected function fillPlaceholders(string $contents): string
    {
        $class = class_basename($this->qualifyClass($this->nameArgument()));
        $model = $this->option('model');
        $model = is_string($model) && $model !== '' ? $this->qualifyModel($model) : null;

        $imports = [
            'Datawell\DataSource',
            'Datawell\Fields\Field',
            'Datawell\Params',
            'Datawell\Representation',
            'Illuminate\Database\Eloquent\Builder as EloquentBuilder',
            'Illuminate\Database\Query\Builder as QueryBuilder',
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

        return Str::replace(array_keys($replacements), array_values($replacements), $contents);
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
