<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;

class ProcessStockAdjustmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StockLockable;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $adjustmentId,
        protected string $approvedBy,
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(InventoryRepository $inventoryRepository, InventoryMovementRepository $movementRepository): void
    {
        $adjustment = StockAdjustment::with('items')->find($this->adjustmentId);

        if (!$adjustment || $adjustment->status !== StockAdjustment::STATUS_APPROVED) {
            return;
        }

        foreach ($adjustment->items as $item) {
            $this->withStockLock($item->item_id, $adjustment->location_id, function () use ($item, $adjustment, $inventoryRepository, $movementRepository) {
                DB::transaction(function () use ($item, $adjustment, $inventoryRepository, $movementRepository) {
                    $inventory = $inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $adjustment->location_id,
                        $item->bin_id,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );

                    $inventory->on_hand += $item->difference_qty;
                    $inventoryRepository->updateStock($inventory);

                    $movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $adjustment->location_id,
                        'bin_id' => $item->bin_id,
                        'transaction_number' => $adjustment->adjustment_no,
                        'source' => 'ADJUSTMENT',
                        'qty' => $item->difference_qty,
                        'balance' => $inventory->on_hand,
                        'transaction_date' => now(),
                        'created_by' => $this->approvedBy,
                    ]);
                });
            });
        }
    }
}
