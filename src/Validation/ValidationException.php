<?php

declare(strict_types=1);

namespace Datawell\Validation;

use Datawell\Contracts\UserSafe;
use RuntimeException;

/**
 * A request failed validation (D09's mechanical checks, D31's masked gates, D07's depth
 * cap). Nothing ran. User-safe by construction: every message here was written for the wire.
 */
class ValidationException extends RuntimeException implements UserSafe
{
    /**
     * @param  array<string, list<string>>  $errors  messages keyed by JSON path
     */
    final public function __construct(protected array $errors, string $message = 'The request is invalid.')
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function withErrors(array $errors): static
    {
        $first = null;

        foreach ($errors as $messages) {
            $first = $messages[0] ?? null;

            if ($first !== null) {
                break;
            }
        }

        return new static($errors, $first ?? 'The request is invalid.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
