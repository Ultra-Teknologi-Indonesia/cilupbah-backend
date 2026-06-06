<?php

namespace Modules\Order\Exceptions;

use Exception;

class DuplicateOrderException extends Exception
{
    protected $code = 409;

    public function __construct(string $marketplace, string $marketplaceOrderId)
    {
        parent::__construct("Order already processed: {$marketplace}:{$marketplaceOrderId}");
    }
}
