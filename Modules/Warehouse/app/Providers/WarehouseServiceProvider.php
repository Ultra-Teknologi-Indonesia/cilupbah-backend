<?php

namespace Modules\Warehouse\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class WarehouseServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Warehouse';

    protected string $nameLower = 'warehouse';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
