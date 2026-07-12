<?php

namespace Modules\Sales\Exceptions;

use Exception;

class InvalidStatusTransitionException extends Exception
{
    protected $code = 422;

    public function __construct(string $from, string $to)
    {
        parent::__construct("Transisi status tidak valid dari '{$from}' ke '{$to}'.");
    }
}
