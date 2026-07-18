<?php

namespace Modules\Purchase\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Observers\PurchaseOrderObserver;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    public function boot(): void
    {
        parent::boot();

        PurchaseOrder::observe(PurchaseOrderObserver::class);
    }
}
