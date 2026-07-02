<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Finance\Services\AutoJournalService;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $totalSignedValue = 0.0;
        $adjustedItemIds = [];

        foreach ($adjustment->items as $item) {
            if ((float) $item->difference_qty === 0.0) {
                continue;
            }

            $this->withStockLock($item->item_id, $adjustment->location_id, function () use ($item, $adjustment, $inventoryRepository, $movementRepository, &$totalSignedValue) {
                DB::transaction(function () use ($item, $adjustment, $inventoryRepository, $movementRepository, &$totalSignedValue) {
                    $inventory = $inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $adjustment->location_id,
                        $item->bin_id,
                    );

                    $avgCost = (float) ($inventory->avg_cost ?? 0);
                    $signedValue = (float) $item->difference_qty * $avgCost;
                    $totalSignedValue += $signedValue;

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
                        'cost_per_unit' => $avgCost > 0 ? $avgCost : null,
                        'total_cost' => $avgCost > 0 ? round($signedValue, 2) : null,
                        'transaction_date' => now(),
                        'created_by' => $this->approvedBy,
                    ]);
                });
            });

            $adjustedItemIds[] = $item->item_id;
        }

        try {
            app(AutoJournalService::class)->forStockAdjustment(
                $adjustment->adjustment_no,
                $adjustment->id,
                $adjustment->approved_at ?? now(),
                $totalSignedValue,
            );
        } catch (\Throwable $e) {
            Log::warning('AutoJournal stock adjustment gagal: ' . $e->getMessage(), [
                'adjustment_no' => $adjustment->adjustment_no,
            ]);
        }

        foreach (array_values(array_unique($adjustedItemIds)) as $itemId) {
            SyncStockToChannelsJob::dispatch($itemId);
        }
    }
}
