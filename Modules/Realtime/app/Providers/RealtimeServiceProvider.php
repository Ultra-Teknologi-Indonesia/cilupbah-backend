<?php

namespace Modules\Realtime\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class RealtimeServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Realtime';

    protected string $nameLower = 'realtime';

    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
