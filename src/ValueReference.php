<?php

declare(strict_types=1);

namespace Datawell;

/**
 * "Values for this slot come from another source, by key" (D22, D23).
 * Resolution runs the referenced source's full pipeline (Phase 2); here it is a declaration.
 */
final class ValueReference
{
    /**
     * @param  array<string, string>  $bindings  referenced-source parameter ⇒ referencing source's parameter
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly array $bindings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $description = ['source' => $this->sourceKey];

        if ($this->bindings !== []) {
            $description['parameters'] = $this->bindings;
        }

        return $description;
    }
}
