<?php

declare(strict_types=1);

namespace Datawell\Exceptions;

use LogicException;

/**
 * A definition violates the contract rules. Raised by the boot-time lint
 * and by the registry; wrong definitions fail loudly at authoring time (D20).
 */
class DefinitionException extends LogicException
{
    /**
     * @param  list<string>  $problems
     */
    public static function fromProblems(array $problems): self
    {
        return new self(
            "Datawell definition lint failed:\n - ".implode("\n - ", $problems),
        );
    }
}
