<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Datawell\Query\QueryRequest;

/**
 * A resolved-shape target (D40): an explicit id list, or "all rows matching this query"
 * minus exceptions. Which shapes an action accepts is a declaration, checked at invocation.
 */
final class Target
{
    /**
     * @param  list<int|string>  $ids
     * @param  list<int|string>  $except
     */
    private function __construct(
        public readonly ?array $ids,
        public readonly ?QueryRequest $query,
        public readonly array $except = [],
    ) {}

    /**
     * @param  list<int|string>  $ids
     */
    public static function ids(array $ids): self
    {
        return new self($ids, null);
    }

    /**
     * @param  list<int|string>  $except
     */
    public static function scope(QueryRequest $query, array $except = []): self
    {
        return new self(null, $query, $except);
    }

    public function isScope(): bool
    {
        return $this->query !== null;
    }
}
