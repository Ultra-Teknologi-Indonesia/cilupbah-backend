<?php

namespace Modules\Supplier\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class SupplierServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Supplier';

    protected string $nameLower = 'supplier';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
