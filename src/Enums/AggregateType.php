<?php

declare(strict_types=1);

namespace Datawell\Enums;

enum AggregateType: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
}
