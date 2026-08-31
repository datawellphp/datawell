<?php

declare(strict_types=1);

namespace Datawell\Console;

use Datawell\Lint\DefinitionLinter;
use Datawell\Registry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'datawell:lint')]
class LintCommand extends Command
{
    protected $signature = 'datawell:lint {--strict : Fail on warnings as well as errors}';

    protected $description = 'Check every registered data source definition against the contract rules';

    public function handle(Registry $registry, DefinitionLinter $linter): int
    {
        $report = $linter->lint($registry);

        foreach ($report->errors as $error) {
            $this->components->error($error);
        }

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }

        if (! $report->passes()) {
            return self::FAILURE;
        }

        if ($report->warnings !== [] && (bool) $this->option('strict')) {
            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d data source(s) linted, %d warning(s).',
            count($registry->all()),
            count($report->warnings),
        ));

        return self::SUCCESS;
    }
}
