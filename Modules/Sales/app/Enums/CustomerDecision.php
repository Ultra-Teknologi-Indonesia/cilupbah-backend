<?php

namespace Modules\Sales\Enums;

enum CustomerDecision: string
{
    case WAITING = 'waiting';
    case CANCEL  = 'cancel';
    case REPLACE = 'replace';
}
