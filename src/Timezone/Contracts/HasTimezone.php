<?php

declare(strict_types=1);

namespace Datawell\Timezone\Contracts;

/**
 * Implemented by the acting user to supply the effective timezone (D13).
 * Return null to fall through to the next link of the resolution chain.
 */
interface HasTimezone
{
    public function timezone(): ?string;
}
