<?php

namespace Modules\Inventory\Jobs;

use App\Traits\StockLockable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Finance\Services\AutoJournalService;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Support\MovingAverageCost;
use Modules\Inventory\Support\StockAdjustmentRule;
use Modules\Warehouse\Services\BinOccupancyGuard;
use Modules\Warehouse\Services\InboundBinPolicy;
use Modules\Warehouse\Services\SkuHomeBinGuard;

class ProcessStockAdjustmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StockLockable;

    public int $tries = 3;

    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $adjustmentId,
        protected string $approvedBy,

        protected ?array $onlyItemIds = null,
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
        ?StockAdjustmentRule $stockAdjustmentRule = null,
    ): void {
        $stockAdjustmentRule ??= app(StockAdjustmentRule::class);
        $adjustment = StockAdjustment::with([
            'items' => function ($query) {
                if ($this->onlyItemIds !== null) {
                    $query->whereIn('id', $this->onlyItemIds);
                }
            },
        ])->find($this->adjustmentId);

        if (! $adjustment) {
            return;
        }

        $totalSignedValue = 0.0;
        $adjustedItemIds = [];

        foreach ($adjustment->items as $item) {
            if ((float) $item->difference_qty === 0.0) {
                continue;
            }

            $this->withStockLock($item->item_id, $adjustment->location_id, function () use ($item, $adjustment, $inventoryRepository, $movementRepository, $stockAdjustmentRule, &$totalSignedValue) {
                DB::transaction(function () use ($item, $adjustment, $inventoryRepository, $movementRepository, $stockAdjustmentRule, &$totalSignedValue) {

                    $existing = $movementRepository->findMovement(
                        $adjustment->adjustment_no,
                        $item->item_id,
                        $adjustment->location_id,
                        $item->bin_id,
                        'ADJUSTMENT',
                    );
                    if ($existing) {
                        $totalSignedValue += (float) ($existing->total_cost ?? 0);

                        return;
                    }

                    if (! empty($item->bin_id)) {
                        app(InboundBinPolicy::class)->assertConsumable(
                            $adjustment->location_id,
                            $item->bin_id,
                            'penyesuaian stok',
                        );
                    }

                    $inventory = $inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $adjustment->location_id,
                        $item->bin_id,
                    );

                    $preOnHand = (float) $inventory->on_hand;
                    $preAvgCost = (float) ($inventory->avg_cost ?? 0);
                    $delta = (float) $item->difference_qty;
                    $itemUnitCost = (float) ($item->unit_cost ?? 0);

                    if (! empty($item->bin_id)
                        && $delta > 0
                        && ! $this->clearsLegacyNegativeOutsideAssignedBin($adjustment, $item, $inventory, $delta)
                    ) {
                        app(BinOccupancyGuard::class)->assertBinFitsSku($item->bin_id, $item->item_id);
                        app(SkuHomeBinGuard::class)->assertSkuFitsBin($adjustment->location_id, $item->item_id, $item->bin_id);
                    }

                    $stockAdjustmentRule->assertAllowed(
                        systemQty: (int) $preOnHand,
                        differenceQty: (int) $delta,
                        actualQty: (int) ($preOnHand + $delta),
                    );

                    if ($delta > 0 && $itemUnitCost > 0) {
                        $inventory->avg_cost = MovingAverageCost::afterReceipt(
                            $preOnHand,
                            $preAvgCost,
                            $delta,
                            $itemUnitCost,
                        );
                    }

                    $movementCost = $delta > 0 && $itemUnitCost > 0 ? $itemUnitCost : $preAvgCost;
                    $signedValue = $delta * $movementCost;
                    $totalSignedValue += $signedValue;

                    $inventory->on_hand += $delta;
                    $inventoryRepository->updateStock($inventory);

                    $movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $adjustment->location_id,
                        'bin_id' => $item->bin_id,
                        'transaction_number' => $adjustment->adjustment_no,
                        'source' => 'ADJUSTMENT',
                        'qty' => $item->difference_qty,
                        'balance' => $inventory->on_hand,
                        'cost_per_unit' => $movementCost > 0 ? $movementCost : null,
                        'total_cost' => $movementCost > 0 ? round($signedValue, 2) : null,
                        'transaction_date' => now(),
                        'created_by' => $this->approvedBy,
                    ]);
                });
            });

            $adjustedItemIds[] = $item->item_id;
        }

        if ($this->onlyItemIds !== null) {
            $totalSignedValue = (float) InventoryMovement::query()
                ->where('transaction_number', $adjustment->adjustment_no)
                ->where('location_id', $adjustment->location_id)
                ->where('source', 'ADJUSTMENT')
                ->sum('total_cost');
        }

        try {
            app(AutoJournalService::class)->forStockAdjustment(
                $adjustment->adjustment_no,
                $adjustment->id,
                now(),
                $totalSignedValue,
            );
        } catch (\Throwable $e) {
            Log::warning('AutoJournal stock adjustment gagal: '.$e->getMessage(), [
                'adjustment_no' => $adjustment->adjustment_no,
            ]);
        }

        foreach (array_values(array_unique($adjustedItemIds)) as $itemId) {
            SyncStockToChannelsJob::dispatch($itemId);
        }
    }

    private function clearsLegacyNegativeOutsideAssignedBin(
        StockAdjustment $adjustment,
        StockAdjustmentItem $item,
        Inventory $inventory,
        float $delta,
    ): bool {
        if (empty($item->bin_id)
            || (float) $inventory->on_hand >= 0
            || (int) $inventory->on_order !== 0
            || $delta <= 0
            || (float) $inventory->on_hand + $delta !== 0.0
        ) {
            return false;
        }

        return SkuRackAssignment::query()
            ->where('location_id', $adjustment->location_id)
            ->where('item_id', $item->item_id)
            ->where('bin_id', '!=', $item->bin_id)
            ->exists();
    }
}
