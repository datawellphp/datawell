<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Closure;

/**
 * A named destination, not an operation (D37): a per-row URL resolver, no handler, no side effect.
 */
class LinkAction extends Action
{
    /** @var (Closure(mixed): ?string)|null */
    protected ?Closure $url = null;

    /**
     * @param  Closure(mixed): ?string  $resolver  row ⇒ app-relative URL
     */
    public function url(Closure $resolver): static
    {
        $this->url = $resolver;

        return $this;
    }

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    public function urlFor(mixed $row): ?string
    {
        return $this->url === null ? null : ($this->url)($row);
    }

    public function kind(): string
    {
        return 'link';
    }
}
