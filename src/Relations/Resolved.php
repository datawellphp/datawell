<?php

declare(strict_types=1);

namespace Datawell\Relations;

use Datawell\Enums\Cardinality;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal A path resolved against a model: how it splits, what it crosses, where it ends.
 */
final class Resolved
{
    /**
     * @param  Cardinality|null  $cardinality  null when no relation was crossed
     * @param  class-string<Model>|null  $related  the model the last relation lands on
     */
    public function __construct(
        public readonly Path $path,
        public readonly ?Cardinality $cardinality,
        public readonly ?string $related,
    ) {}
}
