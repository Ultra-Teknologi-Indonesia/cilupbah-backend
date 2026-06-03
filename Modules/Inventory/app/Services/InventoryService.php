<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Models\Inventory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryService
{
    public function __construct(
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository
    ) {}

    public function getAllStocks(array $filters = []): Collection
    {
        return $this->inventoryRepository->getAllStocks($filters);
    }

    public function getStockByItem(int $itemId): Collection
    {
        return $this->inventoryRepository->getByItem($itemId);
    }

    public function getStockByLocation(int $locationId): Collection
    {
        return $this->inventoryRepository->getByLocation($locationId);
    }

    public function adjust(array $data): Inventory
    {
        return DB::transaction(function () use ($data) {
            $inventory = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? ''
            );

            if (!$inventory) {
                $inventory = $this->inventoryRepository->create([
                    'item_id' => $data['item_id'],
                    'location_id' => $data['location_id'],
                    'bin_id' => $data['bin_id'] ?? null,
                    'batch_no' => $data['batch_no'] ?? '',
                    'serial_no' => $data['serial_no'] ?? '',
                    'expired_date' => $data['expired_date'] ?? null,
                    'on_hand' => 0,
                    'on_order' => 0,
                    'reserved' => 0,
                    'available' => 0,
                ]);
            }

            $newOnHand = $inventory->on_hand + $data['qty'];
            if ($newOnHand < 0) {
                throw new \Exception("Stok tidak mencukupi. Adjustment akan menyebabkan stok minus (on_hand: {$inventory->on_hand}, adjustment: {$data['qty']}).");
            }

            $inventory->on_hand = $newOnHand;
            $this->inventoryRepository->updateStock($inventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['location_id'],
                'bin_id' => $data['bin_id'] ?? null,
                'transaction_number' => $data['transaction_number'] ?? 'ADJ-' . Str::upper(Str::random(8)),
                'source' => 'ADJUSTMENT',
                'qty' => $data['qty'],
                'balance' => $inventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            return $inventory->fresh();
        });
    }

    public function transfer(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $transactionNumber = $data['transaction_number'] ?? 'TRF-' . Str::upper(Str::random(8));

            $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['source_location_id'],
                $data['source_bin_id'] ?? null,
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? ''
            );

            if (!$sourceInventory || $sourceInventory->on_hand < $data['qty']) {
                $current = $sourceInventory ? $sourceInventory->on_hand : 0;
                throw new \Exception("Stok tidak mencukupi di lokasi asal (tersedia: {$current}, diminta: {$data['qty']}).");
            }

            $sourceInventory->on_hand -= $data['qty'];
            $this->inventoryRepository->updateStock($sourceInventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['source_location_id'],
                'bin_id' => $data['source_bin_id'] ?? null,
                'transaction_number' => $transactionNumber,
                'source' => 'TRANSFER_OUT',
                'qty' => -$data['qty'],
                'balance' => $sourceInventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            $destInventory = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['destination_location_id'],
                $data['destination_bin_id'] ?? null,
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? ''
            );

            if (!$destInventory) {
                $destInventory = $this->inventoryRepository->create([
                    'item_id' => $data['item_id'],
                    'location_id' => $data['destination_location_id'],
                    'bin_id' => $data['destination_bin_id'] ?? null,
                    'batch_no' => $data['batch_no'] ?? '',
                    'serial_no' => $data['serial_no'] ?? '',
                    'expired_date' => $data['expired_date'] ?? null,
                    'on_hand' => 0,
                    'on_order' => 0,
                    'reserved' => 0,
                    'available' => 0,
                ]);
            }

            $destInventory->on_hand += $data['qty'];
            $this->inventoryRepository->updateStock($destInventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['destination_location_id'],
                'bin_id' => $data['destination_bin_id'] ?? null,
                'transaction_number' => $transactionNumber,
                'source' => 'TRANSFER_IN',
                'qty' => $data['qty'],
                'balance' => $destInventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            return [
                'source' => $sourceInventory->fresh(),
                'destination' => $destInventory->fresh(),
            ];
        });
    }

    public function putaway(array $data): Inventory
    {
        return DB::transaction(function () use ($data) {
            $transactionNumber = 'PUT-' . Str::upper(Str::random(8));

            $inboundInventory = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['source_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? ''
            );

            if (!$inboundInventory || $inboundInventory->on_hand < $data['qty']) {
                $current = $inboundInventory ? $inboundInventory->on_hand : 0;
                throw new \Exception("Stok di bin inbound tidak mencukupi (tersedia: {$current}, diminta: {$data['qty']}).");
            }

            $inboundInventory->on_hand -= $data['qty'];
            $this->inventoryRepository->updateStock($inboundInventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['location_id'],
                'bin_id' => $data['source_bin_id'],
                'transaction_number' => $transactionNumber,
                'source' => 'PUTAWAY_OUT',
                'qty' => -$data['qty'],
                'balance' => $inboundInventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            $destInventory = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['destination_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? ''
            );

            if (!$destInventory) {
                $destInventory = $this->inventoryRepository->create([
                    'item_id' => $data['item_id'],
                    'location_id' => $data['location_id'],
                    'bin_id' => $data['destination_bin_id'],
                    'batch_no' => $data['batch_no'] ?? '',
                    'serial_no' => $data['serial_no'] ?? '',
                    'expired_date' => $data['expired_date'] ?? null,
                    'on_hand' => 0,
                    'on_order' => 0,
                    'reserved' => 0,
                    'available' => 0,
                ]);
            }

            $destInventory->on_hand += $data['qty'];
            $this->inventoryRepository->updateStock($destInventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['location_id'],
                'bin_id' => $data['destination_bin_id'],
                'transaction_number' => $transactionNumber,
                'source' => 'PUTAWAY_IN',
                'qty' => $data['qty'],
                'balance' => $destInventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            return $destInventory->fresh();
        });
    }

    public function reserveStock(int $itemId, int $locationId, int $qty, string $transactionNumber, string $createdBy): Inventory
    {
        return DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber, $createdBy) {
            $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

            if (!$inventory || $inventory->available < $qty) {
                $current = $inventory ? $inventory->available : 0;
                throw new \Exception("Stok available tidak mencukupi untuk reservasi (tersedia: {$current}, diminta: {$qty}).");
            }

            $inventory->reserved += $qty;
            $this->inventoryRepository->updateStock($inventory);

            $this->movementRepository->create([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'bin_id' => null,
                'transaction_number' => $transactionNumber,
                'source' => 'RESERVE',
                'qty' => -$qty,
                'balance' => $inventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $createdBy,
            ]);

            return $inventory->fresh();
        });
    }

    public function fulfillStock(int $itemId, int $locationId, int $qty, string $transactionNumber, string $createdBy): Inventory
    {
        return DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber, $createdBy) {
            $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

            if (!$inventory || $inventory->on_hand < $qty) {
                throw new \Exception("Stok on_hand tidak mencukupi untuk fulfillment.");
            }

            $inventory->on_hand -= $qty;
            $inventory->reserved -= $qty;
            $this->inventoryRepository->updateStock($inventory);

            $this->movementRepository->create([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'bin_id' => null,
                'transaction_number' => $transactionNumber,
                'source' => 'SALES',
                'qty' => -$qty,
                'balance' => $inventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $createdBy,
            ]);

            return $inventory->fresh();
        });
    }

    public function getMovementHistory(array $filters = []): Collection
    {
        return $this->movementRepository->getHistory($filters);
    }
}
