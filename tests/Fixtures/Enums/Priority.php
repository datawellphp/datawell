<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Enums;

enum Priority: string
{
    case Low = 'low';
    case InReview = 'in_review';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::InReview => 'Under review',
        };
    }
}
