<?php

declare(strict_types=1);

namespace Modules\Sales\Exceptions;

use RuntimeException;

final class SalesReturnTrackingSyncException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $salesReturnId,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }
}
