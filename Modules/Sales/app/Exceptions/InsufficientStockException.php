<?php

namespace Modules\Sales\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    protected $code = 422;

    public function __construct(string $sku, int $available, int $requested)
    {
        parent::__construct("Insufficient stock for SKU {$sku}: available {$available}, requested {$requested}");
    }
}
