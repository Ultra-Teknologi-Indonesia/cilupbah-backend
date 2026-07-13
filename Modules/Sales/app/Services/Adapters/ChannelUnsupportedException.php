<?php

namespace Modules\Sales\Services\Adapters;

use RuntimeException;

class ChannelUnsupportedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Kanal belum didukung: {$reason}");
    }
}
