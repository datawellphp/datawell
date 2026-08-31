<?php

declare(strict_types=1);

namespace Datawell\Enums;

enum Grain: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
}
