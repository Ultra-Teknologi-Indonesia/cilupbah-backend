<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use App\Traits\StockLockable;

class StockService
{
    use StockLockable;

    public function __construct(
        protected InventoryMovementRepository $movementRepository,
        protected InventoryRepository $inventoryRepository,
    ) {}

    public function reserve(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

                if (!$inventory) {
                    throw new \RuntimeException("Inventory tidak ditemukan untuk item {$itemId}.");
                }

                // Stok diizinkan menjadi negatif: order tetap di-reserve walau stok rak
                // kurang memenuhi qty pesanan (available dapat bernilai negatif).
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
