<?php

namespace Modules\Sales\Exceptions;

use Exception;

class CannotDeleteActiveOrderException extends Exception
{
    protected $code = 422;

    public function __construct(string $status)
    {
        parent::__construct("Order dengan status '{$status}' tidak bisa dihapus. Hanya order pending atau dibatalkan yang dapat dihapus.");
    }
}
