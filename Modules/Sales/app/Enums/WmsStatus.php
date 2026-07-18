<?php

namespace Modules\Sales\Enums;

enum WmsStatus: string
{
    case OTHER          = 'OTHER';
    case CREATED        = 'CREATED';
    case PAID           = 'PAID';
    case PROCESS        = 'PROCESS';
    case PICK           = 'PICK';
    case FINISH_PICK    = 'FINISH_PICK';
    case PACK           = 'PACK';
    case FINISH_PACK    = 'FINISH_PACK';
    case READY_TO_SHIP  = 'READY_TO_SHIP';
    case SHIPPED        = 'SHIPPED';
    case COMPLETED      = 'COMPLETED';
    case CANCELLED      = 'CANCELLED';
    case FAILED         = 'FAILED';
    case RETURNED       = 'RETURNED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::FAILED, self::RETURNED], true);
    }
}
