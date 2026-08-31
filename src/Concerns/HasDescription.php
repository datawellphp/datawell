<?php

declare(strict_types=1);

namespace Datawell\Concerns;

/**
 * AI-facing prose (D30, D32): key for machines, label for humans, description for the AI.
 */
trait HasDescription
{
    protected ?string $description = null;

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
