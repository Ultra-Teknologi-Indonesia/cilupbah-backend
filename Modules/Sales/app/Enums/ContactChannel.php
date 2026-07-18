<?php

namespace Modules\Sales\Enums;

enum ContactChannel: string
{
    case MARKETPLACE_CHAT = 'marketplace_chat';
    case WHATSAPP         = 'whatsapp';
    case PHONE            = 'phone';
    case OTHER            = 'other';
}
