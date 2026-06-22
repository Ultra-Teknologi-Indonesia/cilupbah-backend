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
            $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
                ['expired_date' => $data['expired_date'] ?? null],
            );

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

            $destInventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['destination_location_id'],
                $data['destination_bin_id'] ?? null,
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
                ['expired_date' => $data['expired_date'] ?? null],
            );

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

            $destInventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['destination_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
                ['expired_date' => $data['expired_date'] ?? null],
            );

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

    public function getAllPaginated(int $limit = 10)
    {
        return $this->inventoryRepository->getAllPaginated($limit);
    }

    public function getHistoryPaginated(int $limit = 10)
    {
        return $this->movementRepository->getHistoryPaginated($limit);
    }

    public function transferOut(array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($data) {
            $transferNumber = 'TRF-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

            $transfer = $this->transferRepository->create([
                'transfer_number'          => $transferNumber,
                'source_location_id'       => $data['source_location_id'],
                'destination_location_id'  => $data['destination_location_id'],
                'status'                   => InventoryTransfer::STATUS_DRAFT,
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $data['created_by'],
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

                if (! $sourceInventory || $sourceInventory->available < $itemData['qty']) {
                    $current = $sourceInventory ? $sourceInventory->available : 0;
                    throw new \Exception("Stok tidak mencukupi di lokasi asal (tersedia: {$current}, diminta: {$itemData['qty']}).");
                }

                $sourceInventory->reserved += $itemData['qty'];
                $sourceInventory->available -= $itemData['qty'];
                $this->inventoryRepository->updateStock($sourceInventory);
            }

            return $this->transferRepository->findById($transfer->id);
        });
    }

    public function approveTransfer(string $transferId, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
                throw new \Exception("Transfer berstatus {$transfer->status}, tidak bisa di-approve.");
            }

            $transfer->update([
                'status'      => InventoryTransfer::STATUS_APPROVED,
                'approved_by' => $data['approved_by'],
                'assigned_to' => $data['assigned_to'],
                'approved_at' => now(),
            ]);

            return $this->transferRepository->findById($transferId);
        });
    }

    public function cancelTransfer(string $transferId, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if (! in_array($transfer->status, [InventoryTransfer::STATUS_DRAFT, InventoryTransfer::STATUS_APPROVED])) {
                throw new \Exception("Transfer berstatus {$transfer->status}, tidak bisa dibatalkan.");
            }

            foreach ($transfer->items as $item) {
                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no,
                    $item->serial_no,
                );

                if ($sourceInventory) {
                    $sourceInventory->reserved -= $item->qty;
                    $sourceInventory->available += $item->qty;
                    $this->inventoryRepository->updateStock($sourceInventory);
                }
            }

            $transfer->update([
                'status'        => InventoryTransfer::STATUS_CANCELLED,
                'cancelled_by'  => $data['cancelled_by'],
                'cancel_reason' => $data['cancel_reason'] ?? null,
                'cancelled_at'  => now(),
            ]);

            return $this->transferRepository->findById($transferId);
        });
    }

    public function shipTransfer(string $transferId, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status !== InventoryTransfer::STATUS_APPROVED) {
                throw new \Exception("Transfer berstatus {$transfer->status}, tidak bisa dikirim.");
            }

            foreach ($transfer->items as $item) {
                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no,
                    $item->serial_no,
                );

                if (! $sourceInventory) {
                    throw new \Exception("Stok tidak ditemukan untuk item {$item->item_id}.");
                }

                $sourceInventory->reserved -= $item->qty;
                $sourceInventory->on_hand -= $item->qty;
                $this->inventoryRepository->updateStock($sourceInventory);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transfer->source_location_id,
                    'bin_id'             => $item->source_bin_id,
                    'transaction_number' => $transfer->transfer_number,
                    'source'             => 'TRANSFER_OUT',
                    'qty'                => -$item->qty,
                    'balance'            => $sourceInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $data['shipped_by'] ?? $transfer->assigned_to,
                ]);
            }

            $transfer->update([
                'status'     => InventoryTransfer::STATUS_IN_TRANSIT,
                'shipped_at' => now(),
            ]);

            return $this->transferRepository->findById($transferId);
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

            $receivedItems = collect($data['items'] ?? []);

            foreach ($transfer->items as $item) {
                $receivedData = $receivedItems->firstWhere('item_id', $item->item_id);
                $receivedQty  = $receivedData['received_qty'] ?? $item->qty;
                $rejectedQty  = $receivedData['rejected_qty'] ?? 0;
                $condition    = $receivedData['condition'] ?? 'GOOD';
                $itemNotes    = $receivedData['notes'] ?? null;

                if ($receivedQty > $item->qty) {
                    throw new \Exception("Qty diterima ({$receivedQty}) melebihi qty kirim ({$item->qty}).");
                }

                $item->update([
                    'received_qty' => $receivedQty,
                    'rejected_qty' => $rejectedQty,
                    'condition'    => $condition,
                    'item_notes'   => $itemNotes,
                ]);

                if ($receivedQty > 0) {
                    $destInventory = $this->inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $transfer->destination_location_id,
                        $item->destination_bin_id,
                        $item->batch_no,
                        $item->serial_no,
                    );

                    $destInventory->on_hand += $receivedQty;
                    $this->inventoryRepository->updateStock($destInventory);

                    $this->movementRepository->create([
                        'item_id'            => $item->item_id,
                        'location_id'        => $transfer->destination_location_id,
                        'bin_id'             => $item->destination_bin_id,
                        'transaction_number' => $transfer->transfer_number,
                        'source'             => 'TRANSFER_IN',
                        'qty'                => $receivedQty,
                        'balance'            => $destInventory->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => $data['received_by'],
                    ]);
                }
            }

            $transfer->update([
                'status'      => InventoryTransfer::STATUS_RECEIVED,
                'received_by' => $data['received_by'],
                'received_at' => now(),
            ]);

            $inboundService = app(InboundService::class);
            $inboundItems = $transfer->items
                ->filter(fn ($item) => $item->received_qty > 0)
                ->map(fn ($item) => [
                    'item_id'      => $item->item_id,
                    'expected_qty' => $item->received_qty,
                ])->values()->toArray();

            if (! empty($inboundItems)) {
                $inboundService->receiveFromTransfer([
                    'location_id'      => $transfer->destination_location_id,
                    'reference_number' => $transfer->transfer_number,
                    'source_id'        => $transfer->id,
                    'expected_date'    => now()->toDateString(),
                    'created_by'       => $data['received_by'],
                    'items'            => $inboundItems,
                ]);
            }

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

    public function deleteTransfer(string $id): void
    {
        $transfer = $this->transferRepository->findByIdForUpdate($id);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
            throw new \Exception("Hanya transfer DRAFT yang bisa dihapus (status saat ini: {$transfer->status}).");
        }

        $this->transferRepository->delete($id);
    }

    public function markTransferPrinted(string $transferId, string $printedBy): InventoryTransfer
    {
        $transfer = $this->transferRepository->findById($transferId);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        $transfer->update([
            'printed_by' => $printedBy,
            'printed_at' => now(),
        ]);

        return $this->transferRepository->findById($transferId);
    }

    public function splitItem(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $transactionNumber = 'SPL-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

            $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                $data['source_item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
            );

            if (!$sourceInventory || $sourceInventory->on_hand < $data['qty_to_split']) {
                $current = $sourceInventory ? $sourceInventory->on_hand : 0;
                throw new \Exception("Stok tidak mencukupi untuk split (tersedia: {$current}, diminta: {$data['qty_to_split']}).");
            }

            $sourceInventory->on_hand -= $data['qty_to_split'];
            $this->inventoryRepository->updateStock($sourceInventory);

            $this->movementRepository->create([
                'item_id'            => $data['source_item_id'],
                'location_id'        => $data['location_id'],
                'bin_id'             => $data['bin_id'] ?? null,
                'transaction_number' => $transactionNumber,
                'source'             => 'SPLIT_OUT',
                'qty'                => -$data['qty_to_split'],
                'balance'            => $sourceInventory->on_hand,
                'transaction_date'   => now(),
                'created_by'         => $data['created_by'],
            ]);

            $targetInventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['target_item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
            );

            $targetInventory->on_hand += $data['split_into_qty'];
            $this->inventoryRepository->updateStock($targetInventory);

            $this->movementRepository->create([
                'item_id'            => $data['target_item_id'],
                'location_id'        => $data['location_id'],
                'bin_id'             => $data['bin_id'] ?? null,
                'transaction_number' => $transactionNumber,
                'source'             => 'SPLIT_IN',
                'qty'                => $data['split_into_qty'],
                'balance'            => $targetInventory->on_hand,
                'transaction_date'   => now(),
                'created_by'         => $data['created_by'],
            ]);

            return [
                'transaction_number' => $transactionNumber,
                'source'             => $sourceInventory->fresh(),
                'target'             => $targetInventory->fresh(),
            ];
        });
    }
}
