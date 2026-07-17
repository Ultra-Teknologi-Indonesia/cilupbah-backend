<?php

namespace Modules\Sales\Enums;

enum ChannelStatus: string
{
    case UNPAID             = 'UNPAID';
    case READY_TO_SHIP      = 'READY_TO_SHIP';
    case PROCESSED          = 'PROCESSED';
    case SHIPPED            = 'SHIPPED';
    case TO_CONFIRM_RECEIVE = 'TO_CONFIRM_RECEIVE';
    case COMPLETED          = 'COMPLETED';
    case CANCELLED          = 'CANCELLED';
    case RETURN_REQUESTED   = 'RETURN_REQUESTED';
    case RETURNED           = 'RETURNED';
    case IN_CANCEL          = 'IN_CANCEL';
    case UNKNOWN            = 'UNKNOWN';
}
