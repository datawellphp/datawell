<?php

declare(strict_types=1);

namespace Datawell\Lint;

use Datawell\Exceptions\DefinitionException;

final class LintReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * @throws DefinitionException
     */
    public function throwIfErrors(): void
    {
        if ($this->errors !== []) {
            throw DefinitionException::fromProblems($this->errors);
        }
    }
}
