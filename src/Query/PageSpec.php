<?php

declare(strict_types=1);

namespace Datawell\Query;

/**
 * One page block, two modes (D39): cursor `{after, size}` (recommended) or
 * offset `{number, size, withTotal}` for numbered-page UIs.
 */
final class PageSpec
{
    /**
     * @param  'cursor'|'offset'  $mode
     */
    public function __construct(
        public readonly string $mode = 'cursor',
        public readonly ?int $size = null,
        public readonly ?string $after = null,
        public readonly int $number = 1,
        public readonly bool $withTotal = true,
    ) {}

    public function isCursor(): bool
    {
        return $this->mode === 'cursor';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $page = ['mode' => $this->mode];

        if ($this->size !== null) {
            $page['size'] = $this->size;
        }

        if ($this->isCursor()) {
            if ($this->after !== null) {
                $page['after'] = $this->after;
            }
        } else {
            $page['number'] = $this->number;
            $page['withTotal'] = $this->withTotal;
        }

        return $page;
    }
}
