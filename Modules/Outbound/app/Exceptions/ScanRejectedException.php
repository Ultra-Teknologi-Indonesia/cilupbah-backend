<?php

namespace Modules\Outbound\Exceptions;

use Exception;

class ScanRejectedException extends Exception
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
