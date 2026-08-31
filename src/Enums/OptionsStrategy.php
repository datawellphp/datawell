<?php

declare(strict_types=1);

namespace Datawell\Enums;

enum OptionsStrategy: string
{
    case Inline = 'inline';
    case Source = 'source';
    case SelfFacet = 'selfFacet';
}
