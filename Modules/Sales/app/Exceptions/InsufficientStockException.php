<?php

namespace Modules\Sales\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    protected $code = 422;

    public function __construct(
        private string $sku,
        private int $available,
        private int $requested,
    ) {
        parent::__construct("Insufficient stock for SKU {$sku}: available {$available}, requested {$requested}");
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getAvailable(): int
    {
        return $this->available;
    }

    public function getRequested(): int
    {
        return $this->requested;
    }
}
