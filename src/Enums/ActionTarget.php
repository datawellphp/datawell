<?php

declare(strict_types=1);

namespace Datawell\Enums;

enum ActionTarget: string
{
    case Single = 'single';
    case Many = 'many';
    case QueryScope = 'queryScope';
    case Standalone = 'standalone';

    /**
     * Whether this target addresses rows (as opposed to nothing at all).
     */
    public function addressesRows(): bool
    {
        return $this !== self::Standalone;
    }
}
