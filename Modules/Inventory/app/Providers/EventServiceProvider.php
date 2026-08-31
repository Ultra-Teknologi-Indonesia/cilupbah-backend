<?php

namespace Modules\Inventory\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Observers\InventoryReplenishmentObserver;
use Modules\Inventory\Observers\InventoryTransferReplenishmentObserver;
use Modules\Inventory\Observers\SalesOrderItemReplenishmentObserver;
use Modules\Inventory\Observers\SalesOrderReplenishmentObserver;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    protected static $shouldDiscoverEvents = true;

    public function boot(): void
    {
        parent::boot();

        InventoryTransfer::observe(InventoryTransferReplenishmentObserver::class);
        Inventory::observe(InventoryReplenishmentObserver::class);
        SalesOrder::observe(SalesOrderReplenishmentObserver::class);
        SalesOrderItem::observe(SalesOrderItemReplenishmentObserver::class);
    }

    protected function configureEmailVerification(): void {}
}
