<?php

namespace Modules\Sales\Enums;

enum DriverCallStatus: string
{
    case NONE      = 'NONE';
    case PENDING   = 'PENDING';
    case CALLED    = 'CALLED';
    case ACCEPTED  = 'ACCEPTED';
    case PICKED_UP = 'PICKED_UP';
    case FAILED    = 'FAILED';
    case CANCELLED = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::PICKED_UP, self::FAILED, self::CANCELLED], true);
    }
}
