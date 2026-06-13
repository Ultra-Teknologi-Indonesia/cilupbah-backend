<?php

namespace Modules\Outbound\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class OutboundServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Outbound';

    protected string $nameLower = 'outbound';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
