<?php

namespace Modules\Sales\Exceptions;

use Exception;

class ShippingLabelPreparingException extends Exception
{
    protected $code = 202;

    public function __construct(string $message = 'Label sedang disiapkan, coba lagi beberapa saat.')
    {
        parent::__construct($message);
    }
}
