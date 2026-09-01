<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;

class SyncTransferDraftItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [3, 10, 30];

    const ACTION_RESERVE = 'reserve';

    const ACTION_ADJUST = 'adjust';

    const ACTION_RELEASE = 'release';

    public function __construct(
        protected string $transferItemId,
        protected string $action,
        protected int $qty,
        protected string $transferNumber,
        protected string $createdBy,
    ) {
        $this->onQueue(config('queue.names.stock_sync'));
    }

    public function handle(): void
    {
        $item = InventoryTransferItem::find($this->transferItemId);

        if ($this->action === self::ACTION_RELEASE) {
            $item?->delete();

            return;
        }

        if (! $item) {
            return;
        }

        $transfer = InventoryTransfer::find($item->inventory_transfer_id);
        if (! $transfer || $transfer->status !== InventoryTransfer::STATUS_DRAFT) {
            return;
        }
        if (! $transfer->source_location_id) {
            $item->update(['sync_status' => 'FAILED', 'sync_error' => 'Lokasi asal belum diatur']);

            return;
        }

        $item->update(['sync_status' => 'SYNCED', 'sync_error' => null]);
    }
}
