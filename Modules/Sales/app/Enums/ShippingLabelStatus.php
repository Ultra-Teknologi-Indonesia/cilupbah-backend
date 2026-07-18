<?php

namespace Modules\Sales\Enums;

enum ShippingLabelStatus: string
{
    case PENDING  = 'PENDING';
    case READY    = 'READY';
    case PRINTED  = 'PRINTED';
    case FAILED   = 'FAILED';
    case EXPIRED  = 'EXPIRED';
}
