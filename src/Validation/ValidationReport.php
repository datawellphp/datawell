<?php

declare(strict_types=1);

namespace Datawell\Validation;

use Datawell\Query\QueryRequest;

/**
 * The result of a dry-run validation (D38): pass/fail plus every error, without executing.
 */
final class ValidationReport
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly QueryRequest $request,
        public readonly array $errors = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * @throws ValidationException
     */
    public function throwIfFails(): void
    {
        if ($this->fails()) {
            throw ValidationException::withErrors($this->errors);
        }
    }
}
