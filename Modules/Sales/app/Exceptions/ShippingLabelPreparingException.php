<?php

namespace Modules\Sales\Exceptions;

use Exception;

/**
 * Thrown when a shipping label is still being generated in the background
 * by PrepareShopeeShippingLabelJob. Controller should respond with HTTP 202
 * so FE can show "label sedang disiapkan" toast and retry later.
 */
class ShippingLabelPreparingException extends Exception
{
    protected $code = 202;

    public function __construct(string $message = 'Label sedang disiapkan, coba lagi beberapa saat.')
    {
        parent::__construct($message);
    }
}
