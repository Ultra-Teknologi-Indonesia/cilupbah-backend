<?php

namespace Modules\AI\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class AIServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'AI';

    protected string $nameLower = 'ai';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
