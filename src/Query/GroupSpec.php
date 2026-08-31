<?php

declare(strict_types=1);

namespace Datawell\Query;

final class GroupSpec
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $grain = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->grain === null ? ['key' => $this->key] : ['key' => $this->key, 'grain' => $this->grain];
    }
}
