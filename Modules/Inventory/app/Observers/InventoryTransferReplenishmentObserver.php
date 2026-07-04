<?php

namespace Modules\Inventory\Observers;

use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\StockReplenishmentRequest;

class InventoryTransferReplenishmentObserver
{
    public function updated(InventoryTransfer $transfer): void
    {
        if (! $transfer->wasChanged('status')) {
            return;
        }

        if ($transfer->status !== InventoryTransfer::STATUS_RECEIVED) {
            return;
        }

        StockReplenishmentRequest::where('transfer_out_id', $transfer->id)
            ->whereNotIn('status', [
                StockReplenishmentRequest::STATUS_DONE,
                StockReplenishmentRequest::STATUS_REJECTED,
            ])
            ->get()
            ->each(fn (StockReplenishmentRequest $req) => $req->update([
                'status'  => StockReplenishmentRequest::STATUS_DONE,
                'done_at' => now(),
            ]));
    }
}
