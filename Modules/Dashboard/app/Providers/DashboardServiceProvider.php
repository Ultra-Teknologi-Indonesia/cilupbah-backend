<?php

namespace Modules\Dashboard\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class DashboardServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Dashboard';

    protected string $nameLower = 'dashboard';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
