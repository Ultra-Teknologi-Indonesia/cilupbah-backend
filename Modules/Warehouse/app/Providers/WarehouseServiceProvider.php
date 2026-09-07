<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Warehouse\Console\Commands\AuditMultiSkuBins;
use Modules\Warehouse\Console\Commands\CleanupQrPrintFilesCommand;
use Modules\Warehouse\Console\Commands\MigrateStockToNewRacks;
use Modules\Warehouse\Console\Commands\ReconcileBinLayout;
use Nwidart\Modules\Support\ModuleServiceProvider;

class WarehouseServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Warehouse';

    protected string $nameLower = 'warehouse';

    protected array $commands = [
        AuditMultiSkuBins::class,
        CleanupQrPrintFilesCommand::class,
        ReconcileBinLayout::class,
        MigrateStockToNewRacks::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('warehouse:cleanup-qr-print-files')
            ->daily()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
