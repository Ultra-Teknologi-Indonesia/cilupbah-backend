<?php

namespace Modules\Sales\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Sales\Console\Commands\BackfillStatusHistory;
use Modules\Sales\Console\Commands\CleanupBulkLabelBatchesCommand;
use Modules\Sales\Console\Commands\PrepareShopeeLabelsBackfill;
use Modules\Sales\Console\Commands\ReapStaleBulkLabelBatches;
use Modules\Sales\Console\Commands\RelocateOrdersToKecil;
use Modules\Sales\Console\Commands\RestoreTrackingNumbers;
use Modules\Sales\Console\Commands\SyncOrderFinance;
use Modules\Sales\Console\Commands\SyncReturnDetail;
use Modules\Sales\Console\Commands\SyncReturnTracking;
use Modules\Sales\Console\Commands\SyncShippingStatus;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SalesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Sales';

    protected string $nameLower = 'sales';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        SyncOrderFinance::class,
        BackfillStatusHistory::class,
        PrepareShopeeLabelsBackfill::class,
        RestoreTrackingNumbers::class,
        RelocateOrdersToKecil::class,
        SyncReturnTracking::class,
        SyncReturnDetail::class,
        CleanupBulkLabelBatchesCommand::class,
        ReapStaleBulkLabelBatches::class,
        SyncShippingStatus::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('returns:sync-detail')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('sales:cleanup-bulk-label-batches')
            ->daily()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('bulk-shipping-labels:reap-stale')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('sales:sync-shipping-status')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
