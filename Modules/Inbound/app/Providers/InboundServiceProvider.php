<?php

namespace Modules\Inbound\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class InboundServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Inbound';

    protected string $nameLower = 'inbound';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
