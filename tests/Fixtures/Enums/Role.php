<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
