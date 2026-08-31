<?php

declare(strict_types=1);

namespace Datawell\Result;

use Datawell\Query\QueryRequest;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * What a grouped request returns (D39): buckets keyed by group refs and aggregate values,
 * a hard cap with an explicit `truncated` flag — never a silent cap — and the applied echo.
 *
 * @implements Arrayable<string, mixed>
 */
final class BucketResult implements Arrayable, JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  $buckets
     */
    public function __construct(
        public readonly array $buckets,
        public readonly bool $truncated,
        public readonly QueryRequest $applied,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'buckets' => $this->buckets,
            'meta' => ['count' => count($this->buckets), 'truncated' => $this->truncated],
            'applied' => $this->applied->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
