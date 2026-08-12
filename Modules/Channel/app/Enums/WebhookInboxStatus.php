<?php

namespace Modules\Channel\Enums;

enum WebhookInboxStatus: string
{
    case RECEIVED  = 'RECEIVED';
    case PROCESSED = 'PROCESSED';
    case FAILED    = 'FAILED';
    case SKIPPED   = 'SKIPPED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::PROCESSED, self::FAILED, self::SKIPPED], true);
    }
}
