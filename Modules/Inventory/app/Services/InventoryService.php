<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryTransferRepository;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inbound\Services\InboundService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryService
{
    public function __construct(
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
        protected InventoryTransferRepository $transferRepository,
    ) {}


    public function getStockByItem(string $itemId): Collection
    {
        return $this->inventoryRepository->getByItem($itemId);
    }

    public function getStockByLocation(string $locationId): Collection
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

    public function reserveStock(string $itemId, string $locationId, int $qty, string $transactionNumber, string $createdBy): Inventory
    {
        return DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber, $createdBy) {
            $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

            if (!$inventory) {
                throw new \Exception("Inventory tidak ditemukan untuk reservasi (item: {$itemId}).");
            }

            // Stok diizinkan menjadi negatif: reservasi tetap dilakukan walau available
            // kurang dari qty pesanan (available dapat bernilai negatif).
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

    public function fulfillStock(string $itemId, string $locationId, int $qty, string $transactionNumber, string $createdBy): Inventory
    {
        return DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber, $createdBy) {
            $inventory = $this->inventoryRepository->findExactForUpdate($itemId, $locationId, null);

            if (!$inventory) {
                throw new \Exception("Inventory tidak ditemukan untuk fulfillment (item: {$itemId}).");
            }

            // Stok diizinkan menjadi negatif: fulfillment tetap mengurangi on_hand walau
            // melebihi stok rak (on_hand dapat bernilai negatif).
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


    public function getAllPaginated(int $limit = 10)
    {
        return $this->inventoryRepository->getAllPaginated($limit);
    }

    public function getHistoryPaginated(int $limit = 10)
    {
        return $this->movementRepository->getHistoryPaginated($limit);
    }

    // ─── DOCUMENT-BASED TRANSFER (OUT → IN_TRANSIT → IN) ───

    public function transferOut(array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($data) {
            $transferNumber = 'TRF-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

            $transfer = $this->transferRepository->create([
                'transfer_number'          => $transferNumber,
                'source_location_id'       => $data['source_location_id'],
                'destination_location_id'  => $data['destination_location_id'],
                'status'                   => InventoryTransfer::STATUS_IN_TRANSIT,
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $data['created_by'],
                'shipped_at'               => now(),
            ]);

            foreach ($data['items'] as $itemData) {
                $this->transferRepository->createItem([
                    'inventory_transfer_id' => $transfer->id,
                    'item_id'               => $itemData['item_id'],
                    'qty'                   => $itemData['qty'],
                    'source_bin_id'         => $itemData['source_bin_id'] ?? null,
                    'destination_bin_id'    => $itemData['destination_bin_id'] ?? null,
                    'batch_no'              => $itemData['batch_no'] ?? '',
                    'serial_no'             => $itemData['serial_no'] ?? '',
                ]);

                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $itemData['item_id'],
                    $data['source_location_id'],
                    $itemData['source_bin_id'] ?? null,
                    $itemData['batch_no'] ?? '',
                    $itemData['serial_no'] ?? ''
                );

                if (! $sourceInventory || $sourceInventory->on_hand < $itemData['qty']) {
                    $current = $sourceInventory ? $sourceInventory->on_hand : 0;
                    throw new \Exception("Stok tidak mencukupi di lokasi asal (tersedia: {$current}, diminta: {$itemData['qty']}).");
                }

                $sourceInventory->on_hand -= $itemData['qty'];
                $this->inventoryRepository->updateStock($sourceInventory);

                $this->movementRepository->create([
                    'item_id'            => $itemData['item_id'],
                    'location_id'        => $data['source_location_id'],
                    'bin_id'             => $itemData['source_bin_id'] ?? null,
                    'transaction_number' => $transferNumber,
                    'source'             => 'TRANSFER_OUT',
                    'qty'                => -$itemData['qty'],
                    'balance'            => $sourceInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $data['created_by'],
                ]);
            }

            return $this->transferRepository->findById($transfer->id);
        });
    }

    public function transferIn(string $transferId, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status !== InventoryTransfer::STATUS_IN_TRANSIT) {
                throw new \Exception("Transfer berstatus {$transfer->status}, tidak bisa di-receive.");
            }

            foreach ($transfer->items as $item) {
                $qty = $item->qty;

                $destInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->destination_location_id,
                    $item->destination_bin_id,
                    $item->batch_no,
                    $item->serial_no
                );

                if (! $destInventory) {
                    $destInventory = $this->inventoryRepository->create([
                        'item_id'      => $item->item_id,
                        'location_id'  => $transfer->destination_location_id,
                        'bin_id'       => $item->destination_bin_id,
                        'batch_no'     => $item->batch_no,
                        'serial_no'    => $item->serial_no,
                        'on_hand'      => 0,
                        'on_order'     => 0,
                        'reserved'     => 0,
                        'available'    => 0,
                    ]);
                }

                $destInventory->on_hand += $qty;
                $this->inventoryRepository->updateStock($destInventory);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transfer->destination_location_id,
                    'bin_id'             => $item->destination_bin_id,
                    'transaction_number' => $transfer->transfer_number,
                    'source'             => 'TRANSFER_IN',
                    'qty'                => $qty,
                    'balance'            => $destInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $data['received_by'],
                ]);

                $this->transferRepository->updateItemReceivedQty($item->id, $qty);
            }

            $transfer->update([
                'status'      => InventoryTransfer::STATUS_RECEIVED,
                'received_by' => $data['received_by'],
                'received_at' => now(),
            ]);

            $inboundService = app(InboundService::class);
            $inboundItems = $transfer->items->map(fn ($item) => [
                'item_id'      => $item->item_id,
                'expected_qty' => $item->qty,
            ])->toArray();

            $inboundService->receiveFromTransfer([
                'location_id'      => $transfer->destination_location_id,
                'reference_number' => $transfer->transfer_number,
                'source_id'        => $transfer->id,
                'expected_date'    => now()->toDateString(),
                'created_by'       => $data['received_by'],
                'items'            => $inboundItems,
            ]);

            return $this->transferRepository->findById($transferId);
        });
    }

    public function getTransfersPaginated(array $filters = [], int $limit = 10)
    {
        return $this->transferRepository->getTransfersPaginated($filters, $limit);
    }

    public function getTransferById(string $id): ?InventoryTransfer
    {
        return $this->transferRepository->findById($id);
    }
}
