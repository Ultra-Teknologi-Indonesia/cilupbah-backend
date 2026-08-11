<?php

namespace Modules\Channel\Exceptions;

use RuntimeException;

class UnsupportedShadowChannelException extends RuntimeException
{
    public function __construct(string $channelCode)
    {
        parent::__construct("Channel {$channelCode} belum didukung untuk shadow pull.");
    }
}
