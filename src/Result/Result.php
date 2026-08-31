<?php

declare(strict_types=1);

namespace Datawell\Result;

use Datawell\Query\QueryRequest;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * What run() returns: serialized rows, pagination meta, and an echo of the request as
 * it actually ran — defaulted filters and the resting sort included (D35, D47).
 *
 * @implements Arrayable<string, mixed>
 */
final class Result implements Arrayable, JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly PageMeta $meta,
        public readonly QueryRequest $applied,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['rows' => $this->rows, 'meta' => $this->meta->toArray(), 'applied' => $this->applied->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
