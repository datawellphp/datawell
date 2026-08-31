<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Support;

use Datawell\Contracts\UserSafe;
use RuntimeException;

/**
 * A user-safe failure (D44): its message may reach the wire.
 */
class MailboxRejectedException extends RuntimeException implements UserSafe {}
