<?php

namespace Modules\Product\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Product\Console\Commands\ImportCategoryChannelMappings;
use Modules\Product\Console\Commands\PruneUploadHistories;
use Modules\Product\Console\Commands\RecomputeChannelValidation;
use Modules\Product\Console\Commands\RemirrorProductImages;
use Modules\Product\Jobs\PruneUploadHistoriesJob;

use Modules\Product\Console\Commands\ImportJubelioProductsCsv;

class ProductServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Product';

    protected string $nameLower = 'product';

    protected array $commands = [
        ImportCategoryChannelMappings::class,
        PruneUploadHistories::class,
        RecomputeChannelValidation::class,
        RemirrorProductImages::class,
        ImportJubelioProductsCsv::class,
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
