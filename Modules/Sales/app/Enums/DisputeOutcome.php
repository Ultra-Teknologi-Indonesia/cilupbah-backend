<?php

namespace Modules\Sales\Enums;

enum DisputeOutcome: string
{
    case PENDING              = 'PENDING';
    case SELLER_WIN           = 'SELLER_WIN';
    case BUYER_WIN            = 'BUYER_WIN';
    case NO_RETURN_NEEDED     = 'NO_RETURN_NEEDED';
    case SELLER_REFUSE_RETURN = 'SELLER_REFUSE_RETURN';
    case REFUNDED             = 'REFUNDED';
    case CANCELLED            = 'CANCELLED';

    public function requiresPhysicalReturn(): bool
    {
        return in_array($this, [self::BUYER_WIN], true);
    }
}
