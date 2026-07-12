<?php

namespace Modules\Sales\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Sales\Console\Commands\BackfillStatusHistory;
use Modules\Sales\Console\Commands\PrepareShopeeLabelsBackfill;
use Modules\Sales\Console\Commands\RelocateOrdersToKecil;
use Modules\Sales\Console\Commands\RestoreTrackingNumbers;
use Modules\Sales\Console\Commands\SyncOrderFinance;
use Modules\Sales\Console\Commands\SyncReturnDetail;
use Modules\Sales\Console\Commands\SyncReturnTracking;
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
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('returns:sync-detail')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
