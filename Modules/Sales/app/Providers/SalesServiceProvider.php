<?php

namespace Modules\Sales\Providers;

use Modules\Sales\Console\Commands\PrepareShopeeLabelsBackfill;
use Modules\Sales\Console\Commands\SyncOrderFinance;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SalesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Sales';

    protected string $nameLower = 'sales';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        SyncOrderFinance::class,
        PrepareShopeeLabelsBackfill::class,
    ];
}
