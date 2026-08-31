<?php

declare(strict_types=1);

namespace Datawell\Timezone\Concerns;

/**
 * Default HasTimezone implementation for Eloquent users: reads a `timezone` attribute.
 */
trait ReadsTimezoneColumn
{
    public function timezone(): ?string
    {
        // Read the raw attribute: getAttribute() on a model whose loaded attributes do
        // not include the column would treat this very method as a relation and recurse.
        $timezone = $this->getAttributes()[$this->timezoneColumn()] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    protected function timezoneColumn(): string
    {
        return 'timezone';
    }
}
