<?php

declare(strict_types=1);

namespace Datawell;

use Closure;

/**
 * The entity's calling card (D21, D34): id + label, plus an optional URL resolver.
 * Declared once per source; inherited by every reference to it. Safe-to-embed by design (D36).
 */
final class Representation
{
    /** @var (Closure(mixed): ?string)|null */
    private ?Closure $url = null;

    private function __construct(
        public readonly string $label,
        public readonly ?string $id,
    ) {}

    /**
     * @param  string  $label  the field or attribute path carrying the human label
     * @param  string|null  $id  the id column; null defers to the model's key name, or `id`
     */
    public static function make(string $label, ?string $id = null): self
    {
        return new self($label, $id);
    }

    /**
     * @param  Closure(mixed): ?string  $resolver  entity ⇒ app-relative URL
     */
    public function url(Closure $resolver): self
    {
        $this->url = $resolver;

        return $this;
    }

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    public function urlFor(mixed $entity): ?string
    {
        return $this->url === null ? null : ($this->url)($entity);
    }

    /**
     * @return array{id: string, label: string}
     */
    public function describe(string $defaultId = 'id'): array
    {
        return ['id' => $this->id ?? $defaultId, 'label' => $this->label];
    }
}
