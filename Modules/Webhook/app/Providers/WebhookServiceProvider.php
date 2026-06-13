<?php

namespace Modules\Webhook\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class WebhookServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Webhook';

    protected string $nameLower = 'webhook';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
