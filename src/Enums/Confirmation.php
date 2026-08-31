<?php

declare(strict_types=1);

namespace Datawell\Enums;

/**
 * Ordered: Never < WhenDelegated < Always. Floors (destructive, queryScope)
 * may raise the effective level; declared policy can never lower a floor.
 */
enum Confirmation: string
{
    case Never = 'never';
    case WhenDelegated = 'whenDelegated';
    case Always = 'always';

    public function rank(): int
    {
        return match ($this) {
            self::Never => 0,
            self::WhenDelegated => 1,
            self::Always => 2,
        };
    }

    public function atLeast(self $floor): self
    {
        return $this->rank() >= $floor->rank() ? $this : $floor;
    }
}
