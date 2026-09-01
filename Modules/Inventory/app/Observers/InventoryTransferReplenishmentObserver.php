<?php

namespace Modules\Inventory\Observers;

use Modules\Inventory\Jobs\RefreshStockReplenishmentJob;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\StockReplenishmentRequest;

class InventoryTransferReplenishmentObserver
{
    public function updated(InventoryTransfer $transfer): void
    {
        if (! $transfer->wasChanged('status')) {
            return;
        }

        if ($transfer->status === InventoryTransfer::STATUS_RECEIVED) {
            StockReplenishmentRequest::where('transfer_out_id', $transfer->id)
                ->whereNotIn('status', [
                    StockReplenishmentRequest::STATUS_DONE,
                    StockReplenishmentRequest::STATUS_REJECTED,
                    StockReplenishmentRequest::STATUS_CANCELLED,
                ])
                ->get()
                ->each(fn (StockReplenishmentRequest $req) => $req->update([
                    'status' => StockReplenishmentRequest::STATUS_DONE,
                    'done_at' => now(),
                ]));

            RefreshStockReplenishmentJob::dispatch($transfer->destination_location_id)->afterCommit();
        }

        if ($transfer->status === InventoryTransfer::STATUS_CANCELLED) {
            self::cancelLinkedRequests($transfer, detachTransfer: false);

            RefreshStockReplenishmentJob::dispatch($transfer->destination_location_id)->afterCommit();
        }
    }

    public function deleting(InventoryTransfer $transfer): void
    {
        // A deleted transfer must not leave an ACCEPTED replenishment request
        // pointing at a document that no longer exists. Keep terminal states
        // immutable, but close any active request before the FK is nulled.
        self::cancelLinkedRequests($transfer, detachTransfer: true);
    }

    public function deleted(InventoryTransfer $transfer): void
    {
        RefreshStockReplenishmentJob::dispatch($transfer->destination_location_id)->afterCommit();
    }

    private static function cancelLinkedRequests(InventoryTransfer $transfer, bool $detachTransfer): void
    {
        $updates = [
            'status' => StockReplenishmentRequest::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => 'Transfer keluar dibatalkan atau dihapus.',
        ];

        if ($detachTransfer) {
            $updates['transfer_out_id'] = null;
        }

        StockReplenishmentRequest::where('transfer_out_id', $transfer->id)
            ->whereNotIn('status', [
                StockReplenishmentRequest::STATUS_DONE,
                StockReplenishmentRequest::STATUS_REJECTED,
                StockReplenishmentRequest::STATUS_CANCELLED,
            ])
            ->update($updates);
    }
}
