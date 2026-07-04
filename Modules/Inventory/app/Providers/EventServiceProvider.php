<?php

namespace Modules\Inventory\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Observers\InventoryTransferReplenishmentObserver;

class EventServiceProvider extends ServiceProvider
{

    protected $listen = [];

    protected static $shouldDiscoverEvents = true;

    public function boot(): void
    {
        parent::boot();

        InventoryTransfer::observe(InventoryTransferReplenishmentObserver::class);
    }

    protected function configureEmailVerification(): void {}
}
