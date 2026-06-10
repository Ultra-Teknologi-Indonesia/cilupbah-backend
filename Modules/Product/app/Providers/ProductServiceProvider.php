<?php

namespace Modules\Product\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Product\Console\Commands\PruneUploadHistories;
use Modules\Product\Jobs\PruneUploadHistoriesJob;

class ProductServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Product';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'product';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        PruneUploadHistories::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->job(new PruneUploadHistoriesJob())->daily();
    }
}
