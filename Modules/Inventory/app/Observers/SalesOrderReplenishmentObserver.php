<?php

namespace Modules\Inventory\Observers;

use Modules\Inventory\Jobs\RefreshStockReplenishmentJob;
use Modules\Sales\Models\SalesOrder;

class SalesOrderReplenishmentObserver
{
    public function created(SalesOrder $order): void
    {
        $this->dispatch($order->location_id);
    }

    public function updated(SalesOrder $order): void
    {
        if (! $order->wasChanged(['status', 'location_id', 'is_canceled'])) {
            return;
        }

        $this->dispatch($order->location_id);
    }

    private function dispatch(?string $locationId): void
    {
        RefreshStockReplenishmentJob::dispatch($locationId)->afterCommit();
    }
}
