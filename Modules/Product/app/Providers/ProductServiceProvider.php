<?php

namespace Modules\Product\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Product\Console\Commands\MergeMasterProducts;
use Modules\Product\Console\Commands\PruneUploadHistories;
use Modules\Product\Console\Commands\RecomputeChannelValidation;
use Modules\Product\Console\Commands\RemirrorProductImages;
use Modules\Product\Jobs\PruneUploadHistoriesJob;

class ProductServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Product';

    protected string $nameLower = 'product';

    protected array $commands = [
        PruneUploadHistories::class,
        RecomputeChannelValidation::class,
        RemirrorProductImages::class,
        MergeMasterProducts::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->job(new PruneUploadHistoriesJob())->daily();

        $schedule->command('products:recompute-validation --queue')
            ->hourly()
            ->withoutOverlapping();
    }
}
