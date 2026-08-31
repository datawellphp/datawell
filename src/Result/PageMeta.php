<?php

declare(strict_types=1);

namespace Datawell\Result;

/**
 * Pagination facts for one page (D39): offset mode may carry a total; cursor mode never does.
 */
final class PageMeta
{
    private function __construct(
        public readonly string $mode,
        public readonly int $size,
        public readonly bool $hasMore,
        public readonly ?int $number = null,
        public readonly ?int $total = null,
        public readonly ?string $nextCursor = null,
    ) {}

    public static function offset(int $size, int $number, bool $hasMore, ?int $total): self
    {
        return new self('offset', $size, $hasMore, $number, $total);
    }

    public static function cursor(int $size, bool $hasMore, ?string $nextCursor): self
    {
        return new self('cursor', $size, $hasMore, nextCursor: $nextCursor);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = ['mode' => $this->mode, 'size' => $this->size, 'hasMore' => $this->hasMore];

        if ($this->mode === 'offset') {
            $meta['number'] = $this->number;

            if ($this->total !== null) {
                $meta['total'] = $this->total;
            }
        } else {
            $meta['nextCursor'] = $this->nextCursor;
        }

        return $meta;
    }
}
