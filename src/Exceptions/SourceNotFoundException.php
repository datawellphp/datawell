<?php

declare(strict_types=1);

namespace Datawell\Exceptions;

use Datawell\Contracts\UserSafe;
use RuntimeException;

/**
 * Raised for unknown *and* hidden sources alike (D18): an unauthorized source
 * fails as not-found, never forbidden.
 */
class SourceNotFoundException extends RuntimeException implements UserSafe
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Unknown data source "%s".', $key));
    }
}
