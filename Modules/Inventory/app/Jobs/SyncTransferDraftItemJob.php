<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Services\InventoryService;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;

class SyncTransferDraftItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StockLockable;

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

    public function handle(InventoryRepository $inventoryRepository, InventoryMovementRepository $movementRepository): void
    {
        $item = InventoryTransferItem::find($this->transferItemId);

        if ($this->action === self::ACTION_RELEASE) {
            $this->handleRelease($item, $inventoryRepository, $movementRepository);
            return;
        }

        if (!$item) return;

        $transfer = InventoryTransfer::find($item->inventory_transfer_id);
        if (!$transfer || $transfer->status !== InventoryTransfer::STATUS_DRAFT) return;
        if (!$transfer->source_location_id) {
            $item->update(['sync_status' => 'FAILED', 'sync_error' => 'Lokasi asal belum diatur']);
            return;
        }

        $this->withStockLock($item->item_id, $transfer->source_location_id, function () use ($item, $transfer, $inventoryRepository, $movementRepository) {
            DB::transaction(function () use ($item, $transfer, $inventoryRepository, $movementRepository) {
                match ($this->action) {
                    self::ACTION_RESERVE => $this->handleReserve($item, $transfer, $inventoryRepository, $movementRepository),
                    self::ACTION_ADJUST => $this->handleAdjust($item, $transfer, $inventoryRepository, $movementRepository),
                    default => null,
                };
            });
        });
    }

    protected function handleReserve(
        InventoryTransferItem $item,
        InventoryTransfer $transfer,
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
    ): void {
        $sourceInventory = $inventoryRepository->findExactForUpdate(
            $item->item_id,
            $transfer->source_location_id,
            $item->source_bin_id,
            $item->batch_no ?? '',
            $item->serial_no ?? '',
        );

        if (!$sourceInventory || $sourceInventory->available < $this->qty) {
            $current = $sourceInventory ? $sourceInventory->available : 0;
            $item->update([
                'sync_status' => 'FAILED',
                'sync_error' => "Stok tidak mencukupi (tersedia: {$current}, diminta: {$this->qty})",
            ]);
            return;
        }

        $sourceInventory->reserved += $this->qty;
        $sourceInventory->available -= $this->qty;
        $inventoryRepository->updateStock($sourceInventory);

        $movementRepository->create([
            'item_id' => $item->item_id,
            'location_id' => $transfer->source_location_id,
            'bin_id' => $item->source_bin_id,
            'transaction_number' => $this->transferNumber,
            'source' => 'TRANSFER_OUT',
            'qty' => -$this->qty,
            'balance' => $sourceInventory->on_hand,
            'transaction_date' => now(),
            'created_by' => $this->createdBy,
        ]);

        [$transitLocationId, $transitBinId] = app(InventoryService::class)->resolveTransitLocation();

        $transitInventory = $inventoryRepository->findOrCreateForUpdate(
            $item->item_id,
            $transitLocationId,
            $transitBinId,
            $item->batch_no ?? '',
            $item->serial_no ?? '',
        );

        $transitInventory->on_hand += $this->qty;
        $inventoryRepository->updateStock($transitInventory);

        $movementRepository->create([
            'item_id' => $item->item_id,
            'location_id' => $transitLocationId,
            'bin_id' => $transitBinId,
            'transaction_number' => $this->transferNumber,
            'source' => 'TRANSIT_IN',
            'qty' => $this->qty,
            'balance' => $transitInventory->on_hand,
            'transaction_date' => now(),
            'created_by' => $this->createdBy,
        ]);

        $item->update(['sync_status' => 'SYNCED', 'sync_error' => null]);
    }

    protected function handleAdjust(
        InventoryTransferItem $item,
        InventoryTransfer $transfer,
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
    ): void {
        $delta = $this->qty;
        $sourceInventory = $inventoryRepository->findExactForUpdate(
            $item->item_id,
            $transfer->source_location_id,
            $item->source_bin_id,
            $item->batch_no ?? '',
            $item->serial_no ?? '',
        );

        if ($delta > 0 && (!$sourceInventory || $sourceInventory->available < $delta)) {
            $current = $sourceInventory ? $sourceInventory->available : 0;
            $item->update([
                'sync_status' => 'FAILED',
                'sync_error' => "Stok tidak mencukupi untuk penambahan (tersedia: {$current}, diminta: +{$delta})",
            ]);
            return;
        }

        if ($sourceInventory) {
            $sourceInventory->reserved += $delta;
            $sourceInventory->available -= $delta;
            $inventoryRepository->updateStock($sourceInventory);

            $movementRepository->create([
                'item_id' => $item->item_id,
                'location_id' => $transfer->source_location_id,
                'bin_id' => $item->source_bin_id,
                'transaction_number' => $this->transferNumber,
                'source' => 'TRANSFER_OUT',
                'qty' => -$delta,
                'balance' => $sourceInventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $this->createdBy,
            ]);
        }

        [$transitLocationId, $transitBinId] = app(InventoryService::class)->resolveTransitLocation();

        $transitInventory = $inventoryRepository->findOrCreateForUpdate(
            $item->item_id,
            $transitLocationId,
            $transitBinId,
            $item->batch_no ?? '',
            $item->serial_no ?? '',
        );

        $transitInventory->on_hand += $delta;
        $inventoryRepository->updateStock($transitInventory);

        $movementRepository->create([
            'item_id' => $item->item_id,
            'location_id' => $transitLocationId,
            'bin_id' => $transitBinId,
            'transaction_number' => $this->transferNumber,
            'source' => 'TRANSIT_IN',
            'qty' => $delta,
            'balance' => $transitInventory->on_hand,
            'transaction_date' => now(),
            'created_by' => $this->createdBy,
        ]);

        $item->update(['sync_status' => 'SYNCED', 'sync_error' => null]);
    }

    protected function handleRelease(
        ?InventoryTransferItem $item,
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
    ): void {

        if (!$item) {

            return;
        }

        $transfer = InventoryTransfer::find($item->inventory_transfer_id);
        if (!$transfer || !$transfer->source_location_id) return;

        if ($item->sync_status !== 'SYNCED') {
            $item->delete();
            return;
        }

        $this->withStockLock($item->item_id, $transfer->source_location_id, function () use ($item, $transfer, $inventoryRepository, $movementRepository) {
            DB::transaction(function () use ($item, $transfer, $inventoryRepository, $movementRepository) {
                $sourceInventory = $inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                if ($sourceInventory) {
                    $releaseQty = min($this->qty, $sourceInventory->reserved);
                    $sourceInventory->reserved -= $releaseQty;
                    $sourceInventory->available += $releaseQty;
                    $inventoryRepository->updateStock($sourceInventory);

                    $movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $transfer->source_location_id,
                        'bin_id' => $item->source_bin_id,
                        'transaction_number' => $this->transferNumber,
                        'source' => 'TRANSFER_OUT',
                        'qty' => $releaseQty,
                        'balance' => $sourceInventory->on_hand,
                        'transaction_date' => now(),
                        'created_by' => $this->createdBy,
                    ]);
                }

                [$transitLocationId, $transitBinId] = app(InventoryService::class)->resolveTransitLocation();

                $transitInventory = $inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                if ($transitInventory) {
                    $deductQty = min($this->qty, $transitInventory->on_hand);
                    $transitInventory->on_hand -= $deductQty;
                    $inventoryRepository->updateStock($transitInventory);

                    $movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $transitLocationId,
                        'bin_id' => $transitBinId,
                        'transaction_number' => $this->transferNumber,
                        'source' => 'TRANSIT_OUT',
                        'qty' => -$deductQty,
                        'balance' => $transitInventory->on_hand,
                        'transaction_date' => now(),
                        'created_by' => $this->createdBy,
                    ]);
                }

                $item->delete();
            });
        });
    }
}
