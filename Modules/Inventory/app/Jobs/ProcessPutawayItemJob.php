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
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Models\PutawayItemSource;
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

                $unitCost = (float) ($sourceInventory->avg_cost ?? 0);

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
                    'cost_per_unit' => $unitCost > 0 ? $unitCost : null,
                    'total_cost' => $unitCost > 0 ? round($unitCost * (float) $qty, 2) : null,
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

                $preDestOnHand = (float) $destInventory->on_hand;
                $preDestAvgCost = (float) ($destInventory->avg_cost ?? 0);

                $destInventory->on_hand += $qty;

                if ($unitCost > 0) {
                    $newTotal = $preDestOnHand + (float) $qty;
                    $destInventory->avg_cost = $newTotal > 0
                        ? round((($preDestOnHand * $preDestAvgCost) + ((float) $qty * $unitCost)) / $newTotal, 2)
                        : $unitCost;
                }

                $inventoryRepository->updateStock($destInventory);

                $movementRepository->create([
                    'item_id' => $putawayItem->item_id,
                    'location_id' => $putaway->location_id,
                    'bin_id' => $destinationBinId,
                    'transaction_number' => $transactionNumber,
                    'source' => 'PUTAWAY_IN',
                    'qty' => $qty,
                    'balance' => $destInventory->on_hand,
                    'cost_per_unit' => $unitCost > 0 ? $unitCost : null,
                    'total_cost' => $unitCost > 0 ? round($unitCost * (float) $qty, 2) : null,
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

                if ($putaway->source_type === 'INBOUND') {
                    // Distribusi qty ke rincian sumber (FIFO: penerimaan terlama dulu),
                    // lalu sinkron putaway_qty ke tiap inbound_item terkait.
                    $sources = PutawayItemSource::query()
                        ->where('putaway_item_sources.putaway_item_id', $putawayItem->id)
                        ->join('inbound_items', 'inbound_items.id', '=', 'putaway_item_sources.inbound_item_id')
                        ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
                        ->orderBy('inbounds.created_at')
                        ->lockForUpdate()
                        ->select('putaway_item_sources.*', 'inbound_items.inbound_id as inbound_id')
                        ->get();

                    $affectedInboundIds = [];

                    if ($sources->isNotEmpty()) {
                        $remaining = $qty;
                        foreach ($sources as $src) {
                            if ($remaining <= 0) {
                                break;
                            }
                            $capacity = (int) $src->qty - (int) $src->putaway_qty;
                            if ($capacity <= 0) {
                                continue;
                            }
                            $take = min($capacity, $remaining);
                            $src->increment('putaway_qty', $take);
                            InboundItem::where('id', $src->inbound_item_id)->increment('putaway_qty', $take);
                            $affectedInboundIds[$src->inbound_id] = true;
                            $remaining -= $take;
                        }
                    } elseif ($putaway->source_id) {
                        // Fallback dokumen lama tanpa rincian sumber.
                        InboundItem::where('inbound_id', $putaway->source_id)
                            ->where('item_id', $putawayItem->item_id)
                            ->increment('putaway_qty', $qty);
                        $affectedInboundIds[$putaway->source_id] = true;
                    }

                    // Sinkron status tiap penerimaan yang terpengaruh (auto-complete di sini,
                    // bukan lewat PutawayService::complete()).
                    foreach (array_keys($affectedInboundIds) as $inboundId) {
                        $this->recomputeInboundStatus($inboundId);
                    }
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

    /**
     * Hitung ulang status penerimaan dari progres putaway itemnya.
     * Semua item tuntas → COMPLETED; sebagian → PUTAWAY_IN_PROGRESS; nol → RECEIVED.
     * Status DRAFT/CANCELLED tidak disentuh.
     */
    private function recomputeInboundStatus(string $inboundId): void
    {
        $inbound = Inbound::with('items')->find($inboundId);

        if (! $inbound
            || $inbound->items->isEmpty()
            || in_array($inbound->status, [Inbound::STATUS_DRAFT, Inbound::STATUS_CANCELLED], true)) {
            return;
        }

        $allPutaway = $inbound->items->every(fn ($i) => $i->isFullyPutaway());
        $anyPutaway = $inbound->items->contains(fn ($i) => (int) $i->putaway_qty > 0);

        $newStatus = $allPutaway
            ? Inbound::STATUS_COMPLETED
            : ($anyPutaway ? Inbound::STATUS_PUTAWAY_IN_PROGRESS : Inbound::STATUS_RECEIVED);

        if ($inbound->status !== $newStatus) {
            $inbound->update(['status' => $newStatus]);
        }
    }
}
