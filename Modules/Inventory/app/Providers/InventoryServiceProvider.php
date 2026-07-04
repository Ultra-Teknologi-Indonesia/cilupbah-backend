<?php

namespace Modules\Inventory\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Inventory\Console\Commands\AutoDetectStockReplenishment;
use Modules\Inventory\Console\Commands\RebuildAverageCost;

class InventoryServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Inventory';

    protected string $nameLower = 'inventory';

    protected array $commands = [
        RebuildAverageCost::class,
        AutoDetectStockReplenishment::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->job(new \Modules\Inventory\Jobs\ReleaseExpiredReservationsJob)->hourly();

        $schedule->command('replenishment:auto-detect')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
