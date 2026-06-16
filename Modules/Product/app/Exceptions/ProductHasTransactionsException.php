<?php

namespace Modules\Product\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * B2 guard — a product that already carries transactions (inventory ledger,
 * order lines, or an active channel listing) must not be converted into a
 * bundle. App-level equivalent of a 90003 lock violation.
 */
class ProductHasTransactionsException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
