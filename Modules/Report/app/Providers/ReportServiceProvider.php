<?php

namespace Modules\Report\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Report\Console\Commands\CleanupExportJobsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ReportServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Report';

    protected string $nameLower = 'report';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        CleanupExportJobsCommand::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('reports:cleanup-export-jobs')
            ->daily()
            ->withoutOverlapping()
            ->runInBackground();
    }

}
