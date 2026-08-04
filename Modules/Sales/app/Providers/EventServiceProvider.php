<?php

namespace Modules\Sales\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Observers\SalesOrderAuditObserver;
use Modules\Sales\Observers\SalesOrderCancelObserver;
use Modules\Sales\Observers\SalesOrderChannelStatusObserver;
use Modules\Sales\Observers\SalesOrderFinanceResyncObserver;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    public function boot(): void
    {
        parent::boot();

        SalesOrder::observe(SalesOrderCancelObserver::class);
        SalesOrder::observe(SalesOrderAuditObserver::class);
        SalesOrder::observe(SalesOrderChannelStatusObserver::class);
        SalesOrder::observe(SalesOrderFinanceResyncObserver::class);
    }
}
