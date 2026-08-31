<?php

declare(strict_types=1);

namespace Datawell\Enums;

/**
 * The value an operator expects on the wire (D09: every operator implies a shape).
 */
enum ValueShape: string
{
    case None = 'none';
    case Scalar = 'scalar';
    case List = 'list';
    case Range = 'range';
}
