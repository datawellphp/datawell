<?php

declare(strict_types=1);

namespace Datawell\Query;

use Datawell\Validation\ValidationException;

/**
 * @internal Collects errors keyed by JSON path while parsing or validating a request.
 */
final class Errors
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function add(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }

    public function any(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * @throws ValidationException
     */
    public function throwIfAny(): void
    {
        if ($this->errors !== []) {
            throw ValidationException::withErrors($this->errors);
        }
    }
}
