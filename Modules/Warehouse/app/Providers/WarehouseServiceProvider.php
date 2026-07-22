<?php

namespace Modules\Warehouse\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Warehouse\Console\Commands\AuditMultiSkuBins;
use Modules\Warehouse\Console\Commands\ImportBinAllocation;

class WarehouseServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Warehouse';

    protected string $nameLower = 'warehouse';

    protected array $commands = [
        AuditMultiSkuBins::class,
        ImportBinAllocation::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
