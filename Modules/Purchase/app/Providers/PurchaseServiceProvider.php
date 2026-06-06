<?php

namespace Modules\Purchase\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class PurchaseServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Purchase';

    protected string $nameLower = 'purchase';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
