<?php

namespace Modules\Outbound\Exceptions;

use Exception;

class OutboundValidationException extends Exception
{
    protected $code = 422;
}
