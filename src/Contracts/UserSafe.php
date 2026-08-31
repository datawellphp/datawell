<?php

declare(strict_types=1);

namespace Datawell\Contracts;

/**
 * Marks an exception whose message may reach the wire (D44). Anything not marked
 * is logged server-side and surfaced generically — exception text never leaks by default.
 */
interface UserSafe {}
