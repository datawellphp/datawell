<?php

declare(strict_types=1);

namespace Datawell\Concerns;

use Datawell\Support\Key;

/**
 * Labels default from keys, headline-cased, and are overridden only where
 * prose reads better (D32). Requires HasKey.
 */
trait HasLabel
{
    protected ?string $label = null;

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? Key::label($this->getKey());
    }
}
