<?php

namespace Modules\Supplier\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Supplier\Console\Commands\ImportSuppliers;
use Modules\Supplier\Console\Commands\ImportSupplierContacts;

class SupplierServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Supplier';

    protected string $nameLower = 'supplier';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        ImportSuppliers::class,
        ImportSupplierContacts::class,
    ];

}
