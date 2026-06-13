<?php

namespace Modules\Region\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class RegionServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Region';

    protected string $nameLower = 'region';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
