<?php

namespace Modules\Tax\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class TaxServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Tax';

    protected string $nameLower = 'tax';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
