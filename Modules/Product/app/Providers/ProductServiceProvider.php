<?php

namespace Modules\Product\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Product\Console\Commands\PruneUploadHistories;
use Modules\Product\Console\Commands\RecomputeChannelValidation;
use Modules\Product\Jobs\PruneUploadHistoriesJob;

class ProductServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Product';

    protected string $nameLower = 'product';

    protected array $commands = [
        PruneUploadHistories::class,
        RecomputeChannelValidation::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->job(new PruneUploadHistoriesJob())->daily();

        // Rekonsiliasi berkala status Tidak Cocok (jaring pengaman bila event terlewat).
        $schedule->command('products:recompute-validation --queue')
            ->hourly()
            ->withoutOverlapping();
    }
}
