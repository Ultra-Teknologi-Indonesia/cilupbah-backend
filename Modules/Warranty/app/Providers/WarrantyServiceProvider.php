<?php

namespace Modules\Warranty\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class WarrantyServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Warranty';

    protected string $nameLower = 'warranty';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
