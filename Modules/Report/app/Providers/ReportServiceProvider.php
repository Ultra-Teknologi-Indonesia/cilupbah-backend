<?php

namespace Modules\Report\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ReportServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Report';

    protected string $nameLower = 'report';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
