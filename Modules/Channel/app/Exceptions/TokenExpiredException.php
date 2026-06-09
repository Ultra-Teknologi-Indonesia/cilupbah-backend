<?php

namespace Modules\Channel\Exceptions;

class TokenExpiredException extends \RuntimeException
{
    public function __construct(string $shopId, string $message = '')
    {
        parent::__construct($message ?: "Access token expired for shop: {$shopId}");
    }
}
