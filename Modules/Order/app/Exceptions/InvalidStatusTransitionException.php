<?php

namespace Modules\Order\Exceptions;

use Exception;

class InvalidStatusTransitionException extends Exception
{
    protected $code = 422;

    public function __construct(string $from, string $to)
    {
        parent::__construct("Invalid status transition from '{$from}' to '{$to}'");
    }
}
