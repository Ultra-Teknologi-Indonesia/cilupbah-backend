<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;

class ProcessStockOpnameFinalizeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StockLockable;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $opnameId,
        protected string $finalizedBy,
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(InventoryRepository $inventoryRepository, InventoryMovementRepository $movementRepository): void
    {
        $opname = StockOpname::with('items')->find($this->opnameId);

        if (!$opname || $opname->status !== StockOpname::STATUS_FINALIZED) {
            return;
        }

        $itemsWithDifference = $opname->items->filter(fn ($item) => $item->qty_difference !== 0);

        foreach ($itemsWithDifference as $item) {
            $this->withStockLock($item->item_id, $opname->location_id, function () use ($item, $opname, $inventoryRepository, $movementRepository) {
                DB::transaction(function () use ($item, $opname, $inventoryRepository, $movementRepository) {
                    $inventory = $inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $opname->location_id,
                        $item->bin_id,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );

                    $inventory->on_hand += $item->qty_difference;
                    $inventoryRepository->updateStock($inventory);

                    $movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $opname->location_id,
                        'bin_id' => $item->bin_id,
                        'transaction_number' => $opname->opname_no,
                        'source' => 'STOCK_OPNAME',
                        'qty' => $item->qty_difference,
                        'balance' => $inventory->on_hand,
                        'transaction_date' => now(),
                        'created_by' => $this->finalizedBy,
                    ]);
                });
            });
        }
    }
}
