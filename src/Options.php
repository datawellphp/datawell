<?php

declare(strict_types=1);

namespace Datawell;

use Datawell\Enums\OptionsStrategy;

/**
 * Where valid values for a slot come from (D22): inline, a referenced source, or self-faceted.
 * Declared in the definition; resolved lazily through the front door (Phase 2+).
 */
final class Options
{
    /**
     * @param  list<array{id: int|string, label: string}>  $values
     */
    private function __construct(
        public readonly OptionsStrategy $strategy,
        public readonly ?ValueReference $reference = null,
        public readonly array $values = [],
    ) {}

    /**
     * @param  list<array{id: int|string, label: string}>  $values
     */
    public static function inline(array $values): self
    {
        return new self(OptionsStrategy::Inline, values: $values);
    }

    /**
     * @param  array<string, string>  $bindings  referenced-source parameter ⇒ this source's parameter
     */
    public static function source(string $sourceKey, array $bindings = []): self
    {
        return new self(OptionsStrategy::Source, new ValueReference($sourceKey, $bindings));
    }

    public static function selfFacet(): self
    {
        return new self(OptionsStrategy::SelfFacet);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return match ($this->strategy) {
            OptionsStrategy::Inline => ['strategy' => $this->strategy->value, 'values' => $this->values],
            OptionsStrategy::Source => ['strategy' => $this->strategy->value, ...($this->reference?->describe() ?? [])],
            OptionsStrategy::SelfFacet => ['strategy' => $this->strategy->value],
        };
    }
}
