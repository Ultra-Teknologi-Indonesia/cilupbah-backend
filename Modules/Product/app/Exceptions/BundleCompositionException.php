<?php

namespace Modules\Product\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class BundleCompositionException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
