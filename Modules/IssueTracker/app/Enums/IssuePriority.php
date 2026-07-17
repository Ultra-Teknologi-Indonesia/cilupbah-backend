<?php

namespace Modules\IssueTracker\Enums;

enum IssuePriority: string
{
    case LOW      = 'LOW';
    case MEDIUM   = 'MEDIUM';
    case HIGH     = 'HIGH';
    case CRITICAL = 'CRITICAL';

    public function weight(): int
    {
        return match ($this) {
            self::LOW      => 1,
            self::MEDIUM   => 2,
            self::HIGH     => 3,
            self::CRITICAL => 4,
        };
    }
}
