<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Enums;

enum SignatureStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Declined = 'declined';
}
