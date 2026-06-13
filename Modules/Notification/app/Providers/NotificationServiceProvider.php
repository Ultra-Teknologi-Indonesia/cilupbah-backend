<?php

namespace Modules\Notification\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class NotificationServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Notification';

    protected string $nameLower = 'notification';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

}
