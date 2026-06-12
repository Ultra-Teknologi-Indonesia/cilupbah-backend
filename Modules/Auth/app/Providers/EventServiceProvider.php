<?php

namespace Modules\Auth\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    /**
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    protected function configureEmailVerification(): void {}
}
