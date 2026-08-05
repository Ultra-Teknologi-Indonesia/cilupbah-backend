<?php

namespace Modules\Channel\Exceptions;

use Exception;

class ChannelLabelUnsupportedException extends Exception
{
    protected $code = 422;

    public function __construct(string $message = 'Label pengiriman tidak tersedia via API untuk order ini.')
    {
        parent::__construct($message);
    }
}
