<?php

declare(strict_types=1);

namespace Datawell\Enums;

enum Cardinality: string
{
    case Single = 'single';
    case Many = 'many';
}
