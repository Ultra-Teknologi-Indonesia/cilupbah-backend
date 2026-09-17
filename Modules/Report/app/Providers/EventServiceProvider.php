<?php

namespace Modules\Report\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Report\Models\ExportJob;
use Modules\Report\Observers\ExportJobRealtimeObserver;

class EventServiceProvider extends ServiceProvider
{

    protected $listen = [];

    protected static $shouldDiscoverEvents = true;

    protected function configureEmailVerification(): void {}

    public function boot(): void
    {
        parent::boot();

        ExportJob::observe(ExportJobRealtimeObserver::class);
    }
}
