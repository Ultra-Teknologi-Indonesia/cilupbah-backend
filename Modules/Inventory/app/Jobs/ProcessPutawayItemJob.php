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
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\PutawayItemSource;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\PutawayRepository;
use Modules\Sales\Services\BuyerConfirmationService;
use Modules\Warehouse\Services\BinOccupancyGuard;
use Modules\Warehouse\Services\InboundBinPolicy;
use Modules\Warehouse\Services\SkuHomeBinGuard;

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

        if (! $putaway) {
            throw new \RuntimeException('Putaway tidak ditemukan.');
        }

        $this->withStockLock($this->itemId, $putaway->location_id, function () use ($putawayRepository, $inventoryRepository, $movementRepository) {
            DB::transaction(function () use ($putawayRepository, $inventoryRepository, $movementRepository) {
                $putawayItem = $putawayRepository->findItemForUpdate($this->putawayId, $this->itemId);

                if (! $putawayItem) {
                    throw new \RuntimeException('Putaway item tidak ditemukan.');
                }

                $qty = (int) $this->data['qty'];

                if ($qty <= 0) {
                    throw new \RuntimeException('Qty putaway harus lebih dari 0.');
                }

                $remaining = (int) $putawayItem->qty - (int) $putawayItem->putaway_qty;
                if ($qty > $remaining) {
                    throw new \RuntimeException("Qty melebihi sisa kuota ({$remaining}).");
                }

                $putaway = $putawayItem->putaway;
                $destinationBinId = $this->data['destination_bin_id'];
                $transactionNumber = $putaway->putaway_no;

                app(InboundBinPolicy::class)->assertPutawayRoute(
                    $putaway->location_id,
                    $putawayItem->source_bin_id,
                    $destinationBinId,
                );

                app(BinOccupancyGuard::class)->assertBinFitsSku($destinationBinId, $putawayItem->item_id);
                app(SkuHomeBinGuard::class)->assertSkuFitsBin($putaway->location_id, $putawayItem->item_id, $destinationBinId);

                $sourceInventory = $inventoryRepository->findExactForUpdate(
                    $putawayItem->item_id,
                    $putaway->location_id,
                    $putawayItem->source_bin_id,
                    $putawayItem->batch_no ?? '',
                    $putawayItem->serial_no ?? ''
                );

                if (! $sourceInventory) {
                    $sourceInventory = $inventoryRepository->findOrCreateForUpdate(
                        $putawayItem->item_id,
                        $putaway->location_id,
                        $putawayItem->source_bin_id,
                        $putawayItem->batch_no ?? '',
                        $putawayItem->serial_no ?? '',
                    );
                }

                if ($sourceInventory->on_hand < $qty) {
                    throw new \RuntimeException("Stok di source bin tidak mencukupi (tersedia: {$sourceInventory->on_hand}, diminta: {$qty}).");
                }

                $unitCost = (float) ($sourceInventory->avg_cost ?? 0);

                $sourceInventory->on_hand -= $qty;
                $inventoryRepository->updateStock($sourceInventory);

                $actorId = $this->data['created_by'] ?? auth()->id() ?? $putaway->assigned_to ?? 'system';

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
                    'created_by' => $actorId,
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
                    'created_by' => $actorId,
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

                    $sources = PutawayItemSource::query()
                        ->where('putaway_item_sources.putaway_item_id', $putawayItem->id)
                        ->join('inbound_items', 'inbound_items.id', '=', 'putaway_item_sources.inbound_item_id')
                        ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
                        ->orderBy('inbounds.created_at')
                        ->lockForUpdate()
                        ->select('putaway_item_sources.*')
                        ->get();

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
                            InboundItem::where('id', $src->inbound_item_id)->update([
                                'putaway_qty' => DB::raw('putaway_qty + '.(int) $take),
                                'reserved_qty' => DB::raw('GREATEST(reserved_qty - '.(int) $take.', 0)'),
                            ]);
                            $remaining -= $take;
                        }
                    } elseif ($putaway->source_id) {

                        InboundItem::where('inbound_id', $putaway->source_id)
                            ->where('item_id', $putawayItem->item_id)
                            ->update([
                                'putaway_qty' => DB::raw('putaway_qty + '.(int) $qty),
                                'reserved_qty' => DB::raw('GREATEST(reserved_qty - '.(int) $qty.', 0)'),
                            ]);
                    }
                }

                $allFull = PutawayItem::where('putaway_id', $this->putawayId)
                    ->get()
                    ->every(fn ($i) => (int) $i->putaway_qty >= (int) $i->qty);

                if ($allFull) {
                    Putaway::where('id', $this->putawayId)
                        ->where('status', Putaway::STATUS_IN_PROGRESS)
                        ->update([
                            'status' => Putaway::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
                }

                Putaway::where('id', $this->putawayId)->update(['updated_version_at' => now()]);
            });
        });

        SyncStockToChannelsJob::dispatch($this->itemId)->afterCommit();

        $this->releaseOrdersWaitingForStock();
    }

    private function releaseOrdersWaitingForStock(): void
    {
        $locationId = Putaway::where('id', $this->putawayId)->value('location_id');

        if (! $locationId) {
            return;
        }

        try {
            app(BuyerConfirmationService::class)
                ->releaseWaitingForItems([$this->itemId], (string) $locationId);
        } catch (\Throwable $e) {
            Log::warning('Gagal melepas pesanan yang menunggu stok setelah penempatan.', [
                'putaway_id' => $this->putawayId,
                'item_id' => $this->itemId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
