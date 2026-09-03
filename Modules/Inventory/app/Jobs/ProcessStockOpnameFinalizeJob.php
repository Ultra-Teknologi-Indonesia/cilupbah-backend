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
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Support\InventoryOnHandGuard;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Warehouse\Services\InboundBinPolicy;

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

    public function handle(
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
        NotificationDispatcher $notifications,
        ?InventoryOnHandGuard $onHandGuard = null,
    ): void {
        $onHandGuard ??= app(InventoryOnHandGuard::class);
        $opname = StockOpname::with('items')->find($this->opnameId);

        if (! $opname || $opname->status !== StockOpname::STATUS_FINALIZED) {
            return;
        }

        $itemsWithDifference = $opname->items->filter(fn ($item) => $item->qty_difference !== 0);
        $totalSignedValue = 0.0;

        foreach ($itemsWithDifference as $item) {
            $this->withStockLock($item->item_id, $opname->location_id, function () use ($item, $opname, $inventoryRepository, $movementRepository, $onHandGuard, &$totalSignedValue) {
                DB::transaction(function () use ($item, $opname, $inventoryRepository, $movementRepository, $onHandGuard, &$totalSignedValue) {
                    if (! empty($item->bin_id)) {
                        app(InboundBinPolicy::class)->assertConsumable(
                            $opname->location_id,
                            $item->bin_id,
                            'stock opname',
                        );
                    }

                    $inventory = $inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $opname->location_id,
                        $item->bin_id,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );

                    $avgCost = (float) ($inventory->avg_cost ?? 0);
                    $signedValue = (float) $item->qty_difference * $avgCost;
                    $totalSignedValue += $signedValue;

                    $inventory->on_hand = $onHandGuard->resultAfterDelta(
                        (int) $inventory->on_hand,
                        (int) $item->qty_difference,
                        'Finalisasi stock opname',
                    );
                    $inventoryRepository->updateStock($inventory);

                    $movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $opname->location_id,
                        'bin_id' => $item->bin_id,
                        'transaction_number' => $opname->opname_no,
                        'source' => 'STOCK_OPNAME',
                        'qty' => $item->qty_difference,
                        'balance' => $inventory->on_hand,
                        'cost_per_unit' => $avgCost > 0 ? $avgCost : null,
                        'total_cost' => $avgCost > 0 ? round($signedValue, 2) : null,
                        'transaction_date' => now(),
                        'created_by' => $this->finalizedBy,
                    ]);
                });
            });
        }

        foreach ($itemsWithDifference->pluck('item_id')->filter()->unique() as $itemId) {
            SyncStockToChannelsJob::dispatch($itemId);
        }

        try {
            app(AutoJournalService::class)->forStockOpnameAdjustment(
                $opname->opname_no,
                $opname->id,
                $opname->finalized_at ?? now(),
                $totalSignedValue,
            );
        } catch (\Throwable $e) {
            Log::warning('AutoJournal stock opname gagal: '.$e->getMessage(), [
                'opname_no' => $opname->opname_no,
            ]);
        }

        if ($itemsWithDifference->isNotEmpty()) {
            $varianceCount = $itemsWithDifference->count();
            $notifications->toPermission('view-stok-opname', [
                'type' => 'stock_opname_variance',
                'title' => 'Opname punya selisih stok',
                'message' => "Opname {$opname->opname_no} mencatat {$varianceCount} SKU selisih.",
                'data' => [
                    'opname_id' => $opname->id,
                    'opname_no' => $opname->opname_no,
                    'variance_count' => $varianceCount,
                    'total_signed_value' => round($totalSignedValue, 2),
                    'link' => "/dashboard/transaksi-stok/opname/{$opname->id}",
                ],
            ], excludeUserIds: array_filter([$this->finalizedBy]), locationId: $opname->location_id);
        }
    }
}
