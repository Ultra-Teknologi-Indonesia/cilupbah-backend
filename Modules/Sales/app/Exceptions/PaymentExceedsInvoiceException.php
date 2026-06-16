<?php

namespace Modules\Sales\Exceptions;

use Exception;

class PaymentExceedsInvoiceException extends Exception
{
    protected $code = 422;

    public function __construct(float $remaining, float $attempted)
    {
        parent::__construct(
            sprintf('Payment of %.2f exceeds the outstanding invoice balance of %.2f.', $attempted, $remaining)
        );
    }
}
