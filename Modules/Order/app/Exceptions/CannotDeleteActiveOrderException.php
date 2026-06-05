<?php

namespace Modules\Order\Exceptions;

use Exception;

class CannotDeleteActiveOrderException extends Exception
{
    protected $code = 422;

    public function __construct(string $status)
    {
        parent::__construct("Cannot delete order with status '{$status}'. Only pending or cancelled orders can be deleted.");
    }
}
