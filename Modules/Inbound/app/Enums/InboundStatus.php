<?php

namespace Modules\Inbound\Enums;

enum InboundStatus: string
{
    case DRAFT       = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED   = 'COMPLETED';
    case CANCELLED   = 'CANCELLED';

    public function isActive(): bool
    {
        return in_array($this, [self::DRAFT, self::IN_PROGRESS], true);
    }
}
