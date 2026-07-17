<?php

namespace Modules\Sales\Enums;

enum CancelChannel: string
{
    case MANUAL      = 'manual';
    case MARKETPLACE = 'marketplace';
    case SYSTEM      = 'system';
    case BUYER       = 'buyer';
    case SELLER      = 'seller';
}
