<?php

namespace Modules\Product\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{

    protected $listen = [];

    protected static $shouldDiscoverEvents = true;

    protected function configureEmailVerification(): void {}

    public function boot()
    {
        parent::boot();

        \Modules\Product\Models\Product::observe(\Modules\Product\Observers\ProductObserver::class);
    }
}
