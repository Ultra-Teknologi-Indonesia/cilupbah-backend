<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Repositories\PutawayRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inbound\Models\InboundItem;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;

class ProcessPutawayItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StockLockable;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $putawayId,
        protected string $itemId,
        protected array $data,
    ) {
        $this->onQueue(config('queue.names.stock_default'));
    }

    public function handle(
        PutawayRepository $putawayRepository,
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
    ): void {
        $putaway = $putawayRepository->findById($this->putawayId);

        if (!$putaway) {
            throw new \RuntimeException("Putaway tidak ditemukan.");
        }

        $this->withStockLock($this->itemId, $putaway->location_id, function () use ($putawayRepository, $inventoryRepository, $movementRepository) {
            DB::transaction(function () use ($putawayRepository, $inventoryRepository, $movementRepository) {
                $putawayItem = $putawayRepository->findItemForUpdate($this->putawayId, $this->itemId);

                if (!$putawayItem) {
                    throw new \RuntimeException("Putaway item tidak ditemukan.");
                }

                $remaining = $putawayItem->qty - $putawayItem->putaway_qty;
                $qty = min($this->data['qty'], $remaining);

                if ($qty <= 0) {
                    throw new \RuntimeException("Item sudah selesai di-putaway.");
                }

                $putaway = $putawayItem->putaway;
                $destinationBinId = $this->data['destination_bin_id'];
                $transactionNumber = $putaway->putaway_no;

                $destBin = \Modules\Warehouse\Models\LocationBin::find($destinationBinId);
                if ($destBin && $destBin->max_qty) {
                    $currentBinQty = (int) \Modules\Inventory\Models\Inventory::where('bin_id', $destinationBinId)
                        ->where('location_id', $putaway->location_id)
                        ->sum('on_hand');
                    $binRemaining = $destBin->max_qty - $currentBinQty;
                    if ($qty > $binRemaining) {
                        throw new \RuntimeException("Kapasitas rak tidak cukup (sisa: {$binRemaining}, diminta: {$qty}).");
                    }
                }

                $sourceInventory = $inventoryRepository->findExactForUpdate(
                    $putawayItem->item_id,
                    $putaway->location_id,
                    $putawayItem->source_bin_id,
                    $putawayItem->batch_no ?? '',
                    $putawayItem->serial_no ?? ''
                );

                if (!$sourceInventory || $sourceInventory->on_hand < $qty) {
                    $current = $sourceInventory ? $sourceInventory->on_hand : 0;
                    throw new \RuntimeException("Stok di source bin tidak mencukupi (tersedia: {$current}, diminta: {$qty}).");
                }

                $sourceInventory->on_hand -= $qty;
                $inventoryRepository->updateStock($sourceInventory);

                $movementRepository->create([
                    'item_id' => $putawayItem->item_id,
                    'location_id' => $putaway->location_id,
                    'bin_id' => $putawayItem->source_bin_id,
                    'transaction_number' => $transactionNumber,
                    'source' => 'PUTAWAY_OUT',
                    'qty' => -$qty,
                    'balance' => $sourceInventory->on_hand,
                    'transaction_date' => now(),
                    'created_by' => 'system',
                ]);

                $destInventory = $inventoryRepository->findOrCreateForUpdate(
                    $putawayItem->item_id,
                    $putaway->location_id,
                    $destinationBinId,
                    $putawayItem->batch_no ?? '',
                    $putawayItem->serial_no ?? '',
                );

                $destInventory->on_hand += $qty;
                $inventoryRepository->updateStock($destInventory);

                $movementRepository->create([
                    'item_id' => $putawayItem->item_id,
                    'location_id' => $putaway->location_id,
                    'bin_id' => $destinationBinId,
                    'transaction_number' => $transactionNumber,
                    'source' => 'PUTAWAY_IN',
                    'qty' => $qty,
                    'balance' => $destInventory->on_hand,
                    'transaction_date' => now(),
                    'created_by' => 'system',
                ]);

                $putawayItem->update([
                    'putaway_qty' => $putawayItem->putaway_qty + $qty,
                    'destination_bin_id' => $destinationBinId,
                ]);

                $placement = PutawayPlacement::where('putaway_item_id', $putawayItem->id)
                    ->where('bin_id', $destinationBinId)
                    ->first();

                if ($placement) {
                    $placement->increment('qty', $qty);
                } else {
                    PutawayPlacement::create([
                        'putaway_item_id' => $putawayItem->id,
                        'bin_id' => $destinationBinId,
                        'qty' => $qty,
                    ]);
                }

                if ($putaway->source_type === 'INBOUND' && $putaway->source_id) {
                    InboundItem::where('inbound_id', $putaway->source_id)
                        ->where('item_id', $putawayItem->item_id)
                        ->increment('putaway_qty', $qty);
                }

                $allDone = PutawayItem::where('putaway_id', $this->putawayId)
                    ->get(['qty', 'putaway_qty'])
                    ->every(fn ($i) => $i->putaway_qty >= $i->qty);

                if ($allDone) {
                    Putaway::where('id', $this->putawayId)
                        ->where('status', '!=', Putaway::STATUS_COMPLETED)
                        ->update([
                            'status' => Putaway::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
                }
            });
        });
    }
}
