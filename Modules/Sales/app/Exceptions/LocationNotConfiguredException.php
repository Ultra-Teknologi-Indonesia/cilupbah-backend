<?php

namespace Modules\Sales\Exceptions;

use Exception;

class LocationNotConfiguredException extends Exception
{
    protected $code = 422;

    public function __construct(string $salesOrderNo)
    {
        parent::__construct("Lokasi gudang untuk order '{$salesOrderNo}' tidak dapat ditentukan. Atur lokasi default atau mapping channel-gudang.");
    }
}
