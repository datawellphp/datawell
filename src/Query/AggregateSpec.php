<?php

declare(strict_types=1);

namespace Datawell\Query;

final class AggregateSpec
{
    public function __construct(
        public readonly string $fn,
        public readonly ?string $field = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->field === null ? ['fn' => $this->fn] : ['fn' => $this->fn, 'field' => $this->field];
    }
}
