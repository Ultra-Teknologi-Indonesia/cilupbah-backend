<?php

namespace Modules\Inventory\Observers;

use Modules\Inventory\Jobs\RefreshStockReplenishmentJob;
use Modules\Inventory\Models\Inventory;

class InventoryReplenishmentObserver
{
    public function saved(Inventory $inventory): void
    {
        if (! $inventory->wasChanged(['item_id', 'location_id', 'bin_id', 'on_hand', 'on_order'])) {
            return;
        }

        RefreshStockReplenishmentJob::dispatch($inventory->location_id)->afterCommit();
    }
}
