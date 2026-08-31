<?php

declare(strict_types=1);

namespace Datawell\Validation;

use Closure;
use Datawell\Execution\Context;
use Datawell\ValueReference;

/**
 * Answers "does this value exist within the caller's scoped view of the referenced
 * source?" (D23). The executor installs the real answer — a scoped entity lookup —
 * at boot; without one, references validate rules only and this says yes.
 */
class ProvenanceResolver
{
    /** @var (Closure(ValueReference, mixed, array<string, mixed>, Context): bool)|null */
    protected ?Closure $resolver = null;

    /**
     * @param  Closure(ValueReference, mixed, array<string, mixed>, Context): bool  $resolver
     */
    public function using(Closure $resolver): static
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $parameters  the referencing request's parameters, for bindings
     */
    public function exists(ValueReference $reference, mixed $value, array $parameters, Context $context): bool
    {
        return $this->resolver === null ? true : ($this->resolver)($reference, $value, $parameters, $context);
    }
}
