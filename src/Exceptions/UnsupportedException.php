<?php

declare(strict_types=1);

namespace Datawell\Exceptions;

use LogicException;

/**
 * A declared capability the executor cannot compile yet. Internal — never user-safe —
 * because it describes the package's own gap, not the caller's request.
 */
class UnsupportedException extends LogicException {}
