<?php

declare(strict_types=1);

namespace Datawell\Query;

final class SortSpec
{
    /**
     * @param  'asc'|'desc'  $direction
     */
    public function __construct(
        public readonly string $key,
        public readonly string $direction = 'asc',
    ) {}

    /**
     * @return array{key: string, direction: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'direction' => $this->direction];
    }
}
