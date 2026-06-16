<?php

namespace Modules\Sales\Exceptions;

use Exception;

class LocationNotConfiguredException extends Exception
{
    protected $code = 422;

    public function __construct(string $salesOrderNo)
    {
        parent::__construct("No warehouse location could be resolved for order '{$salesOrderNo}'. Configure a default location or a channel-warehouse mapping.");
    }
}
