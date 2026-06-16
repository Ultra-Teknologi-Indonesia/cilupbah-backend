<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Sales\Exceptions\InsufficientStockException;
use App\Traits\StockLockable;

class StockService
{
    use StockLockable;

    public function __construct(
        protected InventoryMovementRepository $movementRepository,
        protected InventoryRepository $inventoryRepository,
    ) {}

    /**
     * Reserve stock for an order line.
     *
     * @param bool $enforce When true (manual orders) availability is enforced and
     *                      missing/insufficient stock raises InsufficientStockException (422).
     *                      When false (marketplace orders already committed at the channel)
     *                      the reservation is recorded regardless and only logged, so the
     *                      webhook never hard-fails on an out-of-sync stock count.
     */
    public function reserve(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber, bool $enforce = true): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($sku, $itemId, $locationId, $qty, $transactionNumber, $enforce) {
            DB::transaction(function () use ($sku, $itemId, $locationId, $qty, $transactionNumber, $enforce) {
                $inventory = $enforce
                    ? $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null)
                    : $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);

                if (!$inventory) {
                    // Manual flow: no inventory row means nothing is available.
                    throw new InsufficientStockException($sku, 0, $qty);
                }

                $available = $inventory->on_hand - $inventory->on_order - $inventory->reserved;

                if ($available < $qty) {
                    if ($enforce) {
                        throw new InsufficientStockException($sku, max(0, $available), $qty);
                    }

                    Log::warning('Stock oversold on channel reservation', [
                        'sku'                => $sku,
                        'item_id'            => $itemId,
                        'location_id'        => $locationId,
                        'available'          => $available,
                        'requested'          => $qty,
                        'transaction_number' => $transactionNumber,
                    ]);
                }

                $inventory->reserved += $qty;
                $this->inventoryRepository->updateStock($inventory);

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_RESERVE',
                    'qty'                => -$qty,
                    'balance'            => $inventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
            });
        });
    }

    public function pick(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

                if (!$inventory) {
                    throw new \RuntimeException("Inventory tidak ditemukan untuk item {$itemId}.");
                }

                $inventory->on_hand -= $qty;
                $inventory->reserved -= $qty;
                $this->inventoryRepository->updateStock($inventory);

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_PICK',
                    'qty'                => -$qty,
                    'balance'            => $inventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
            });
        });
    }

    public function ship(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

                if (!$inventory) {
                    throw new \RuntimeException("Inventory tidak ditemukan untuk item {$itemId}.");
                }

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_SHIP',
                    'qty'                => 0,
                    'balance'            => $inventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
            });
        });
    }

    public function restore(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

                if (!$inventory) {
                    throw new \RuntimeException("Inventory tidak ditemukan untuk item {$itemId}.");
                }

                $inventory->on_hand += $qty;
                $this->inventoryRepository->updateStock($inventory);

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_RESTORE',
                    'qty'                => $qty,
                    'balance'            => $inventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
            });
        });
    }

    public function cancel(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

                if (!$inventory) {
                    throw new \RuntimeException("Inventory tidak ditemukan untuk item {$itemId}.");
                }

                $inventory->reserved -= $qty;
                $this->inventoryRepository->updateStock($inventory);

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_CANCEL',
                    'qty'                => $qty,
                    'balance'            => $inventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
            });
        });
    }
}
