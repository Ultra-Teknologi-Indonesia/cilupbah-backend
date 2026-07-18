<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\ReservedStock;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;

class ProcessReservedStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StockLockable;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $reservedStockId,
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(InventoryRepository $inventoryRepository, InventoryMovementRepository $movementRepository): void
    {
        $reservedStock = ReservedStock::with('items')->find($this->reservedStockId);

        if (!$reservedStock || $reservedStock->status !== ReservedStock::STATUS_ACTIVE) {
            return;
        }

        foreach ($reservedStock->items as $item) {
            $this->withStockLock($item->item_id, $reservedStock->location_id, function () use ($item, $reservedStock, $inventoryRepository, $movementRepository) {
                DB::transaction(function () use ($item, $reservedStock, $inventoryRepository, $movementRepository) {
                    $inventory = $inventoryRepository->findExactForUpdate(
                        $item->item_id,
                        $reservedStock->location_id,
                        $item->bin_id,
                    );

                    if (!$inventory) {
                        throw new \RuntimeException("Inventory tidak ditemukan untuk item {$item->item_id}.");
                    }

                    $inventory->on_order += $item->qty;
                    $inventoryRepository->updateStock($inventory);

                    $movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $reservedStock->location_id,
                        'bin_id' => $item->bin_id,
                        'transaction_number' => $reservedStock->reserved_stock_no,
                        'source' => 'RESERVE',
                        'qty' => -$item->qty,
                        'balance' => $inventory->on_hand,
                        'transaction_date' => now(),
                        'created_by' => $reservedStock->created_by,
                    ]);
                });
            });
        }
    }
}
