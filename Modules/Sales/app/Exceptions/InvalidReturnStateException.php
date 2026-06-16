<?php

namespace Modules\Sales\Exceptions;

use Exception;

class InvalidReturnStateException extends Exception
{
    protected $code = 409;
}
