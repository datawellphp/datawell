<?php

declare(strict_types=1);

namespace Datawell\Result;

/**
 * An entity reference: `{ id, label, url? }` (D21, D34). Safe to embed anywhere.
 */
final class EntityRef
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $label,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return array{id: int|string, label: string, url?: string}
     */
    public function toArray(): array
    {
        $ref = ['id' => $this->id, 'label' => $this->label];

        if ($this->url !== null) {
            $ref['url'] = $this->url;
        }

        return $ref;
    }
}
