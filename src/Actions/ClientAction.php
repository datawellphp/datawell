<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Closure;

/**
 * A declared front-end intent (D37): each consumer registers a handler for the key
 * and hides the action if none is registered. Optional per-row payload.
 */
class ClientAction extends Action
{
    /** @var (Closure(mixed): array<string, mixed>)|null */
    protected ?Closure $payload = null;

    /**
     * @param  Closure(mixed): array<string, mixed>  $resolver  row ⇒ payload
     */
    public function payload(Closure $resolver): static
    {
        $this->payload = $resolver;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(mixed $row): array
    {
        return $this->payload === null ? [] : ($this->payload)($row);
    }

    public function kind(): string
    {
        return 'client';
    }
}
