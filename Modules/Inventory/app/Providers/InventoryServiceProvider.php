<?php

namespace Modules\Inventory\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Inventory\Console\Commands\AutoDetectStockReplenishment;
use Modules\Inventory\Console\Commands\BackfillInboundMovementSource;
use Modules\Inventory\Console\Commands\CleanupDraftTransitStock;
use Modules\Inventory\Console\Commands\BackfillOrderAllocations;
use Modules\Inventory\Console\Commands\BackfillTransitInbounds;
use Modules\Inventory\Console\Commands\RebuildAverageCost;
use Modules\Inventory\Console\Commands\ReconcileOnOrder;
use Modules\Inventory\Console\Commands\ReconcileOrderAllocationLedger;
use Modules\Inventory\Console\Commands\ReconcileInboundBackfillConsumption;
use Modules\Inventory\Console\Commands\PurgeReversalMovements;
use Modules\Inventory\Console\Commands\ValidateBaselineImport;
use Modules\Inventory\Console\Commands\ImportBaselineStock;
use Modules\Inventory\Console\Commands\ResetStock;

class InventoryServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Inventory';

    protected string $nameLower = 'inventory';

    protected array $commands = [
        RebuildAverageCost::class,
        AutoDetectStockReplenishment::class,
        BackfillTransitInbounds::class,
        BackfillOrderAllocations::class,
        ReconcileOnOrder::class,
        ReconcileOrderAllocationLedger::class,
        ReconcileInboundBackfillConsumption::class,
        BackfillInboundMovementSource::class,
        CleanupDraftTransitStock::class,
        PurgeReversalMovements::class,
        ValidateBaselineImport::class,
        ImportBaselineStock::class,
        ResetStock::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->job(new \Modules\Inventory\Jobs\ReleaseExpiredReservationsJob)
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('replenishment:auto-detect')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('inventory:reconcile-on-order')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground();
    }
}
