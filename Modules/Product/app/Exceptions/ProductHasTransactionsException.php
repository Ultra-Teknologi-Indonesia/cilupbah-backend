<?php

namespace Modules\Product\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductHasTransactionsException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
