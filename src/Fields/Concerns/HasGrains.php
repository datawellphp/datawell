<?php

declare(strict_types=1);

namespace Datawell\Fields\Concerns;

use Datawell\Enums\Grain;

/**
 * Date bucketing grains — exists only on date/time fields (D33: a BooleanField cannot write it).
 */
trait HasGrains
{
    /** @var list<Grain> */
    protected array $grains = [];

    /**
     * @param  list<Grain|string>  $grains
     */
    public function groupable(bool $groupable = true, array $grains = []): static
    {
        $this->groupable = $groupable;
        $this->grains = array_map(
            static fn (Grain|string $grain): Grain => $grain instanceof Grain ? $grain : Grain::from($grain),
            $grains,
        );

        return $this;
    }

    /**
     * @return list<Grain>
     */
    public function getGrains(): array
    {
        return $this->grains;
    }

    /**
     * @return array<string, mixed>
     */
    protected function describeExtra(): array
    {
        return $this->grains === []
            ? []
            : ['grains' => array_map(static fn (Grain $grain): string => $grain->value, $this->grains)];
    }
}
