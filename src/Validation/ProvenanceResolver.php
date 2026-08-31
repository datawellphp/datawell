<?php

declare(strict_types=1);

namespace Datawell\Validation;

use Datawell\Execution\Context;
use Datawell\ValueReference;

/**
 * Answers "does this value exist within the caller's scoped view of the referenced
 * source?" (D23). The executor supplies the real answer by running that source's
 * pipeline (slice 2); until then a reference validates rules only and this says yes.
 */
class ProvenanceResolver
{
    /**
     * @param  array<string, mixed>  $parameters  the referencing request's parameters, for bindings
     */
    public function exists(ValueReference $reference, mixed $value, array $parameters, Context $context): bool
    {
        return true;
    }
}
