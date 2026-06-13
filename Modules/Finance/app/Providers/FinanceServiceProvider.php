<?php

namespace Modules\Finance\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class FinanceServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Finance';

    protected string $nameLower = 'finance';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
