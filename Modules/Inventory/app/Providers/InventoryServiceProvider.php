<?php

namespace Modules\Inventory\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class InventoryServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Inventory';

    protected string $nameLower = 'inventory';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->job(new \Modules\Inventory\Jobs\ReleaseExpiredReservationsJob)->hourly();
    }
}
