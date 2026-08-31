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
        $timezone = $this->getAttribute($this->timezoneColumn());

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    protected function timezoneColumn(): string
    {
        return 'timezone';
    }
}
