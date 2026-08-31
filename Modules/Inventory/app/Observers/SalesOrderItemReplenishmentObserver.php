<?php

namespace Modules\Inventory\Observers;

use Modules\Inventory\Jobs\RefreshStockReplenishmentJob;
use Modules\Sales\Models\SalesOrderItem;

class SalesOrderItemReplenishmentObserver
{
    public function created(SalesOrderItem $item): void
    {
        $this->dispatch($item);
    }

    public function updated(SalesOrderItem $item): void
    {
        if (! $item->wasChanged(['item_id', 'qty_in_base', 'order_id'])) {
            return;
        }

        $this->dispatch($item);
    }

    public function deleted(SalesOrderItem $item): void
    {
        $this->dispatch($item);
    }

    private function dispatch(SalesOrderItem $item): void
    {
        RefreshStockReplenishmentJob::dispatch($item->order?->location_id)->afterCommit();
    }
}
