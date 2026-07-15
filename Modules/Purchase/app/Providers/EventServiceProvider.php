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

        // F5: auto-create Inbound DRAFT saat PO transisi ke OPEN.
        PurchaseOrder::observe(PurchaseOrderObserver::class);
    }
}
