<?php

namespace Modules\Sales\Exceptions;

use Exception;

class DuplicateOrderException extends Exception
{
    protected $code = 409;

    public function __construct(string $marketplace, string $marketplaceOrderId)
    {
        parent::__construct("Order sudah diproses sebelumnya: {$marketplace}:{$marketplaceOrderId}");
    }
}
