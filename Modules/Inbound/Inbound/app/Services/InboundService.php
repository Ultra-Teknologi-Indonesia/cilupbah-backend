<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Repositories\InboundRepository;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundAssignment;
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Services\InventoryService;
use Modules\Warehouse\Services\LocationBinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboundService
{
    public function __construct(
        protected InboundRepository $inboundRepository,
        protected InventoryService $inventoryService,
        protected LocationBinService $binService
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->inboundRepository->getAllPaginated($limit);
    }

    public function getById(int $id): ?Inbound
    {
        return $this->inboundRepository->findById($id);
    }

    // ─── TAHAP 1: CREATE DRAFT (GRN) ───

    public function createDraft(array $data): Inbound
    {
        return DB::transaction(function () use ($data) {
            $data['transaction_number'] = $data['transaction_number'] ?? 'INB-' . Str::upper(Str::random(8));
            $data['status'] = Inbound::STATUS_DRAFT;

            $inbound = $this->inboundRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['inbound_id'] = $inbound->id;
                $this->inboundRepository->createItem($itemData);
            }

            return $inbound->load('items');
        });
    }

    /** Create GRN from Purchase Order */
    public function receiveFromPO(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_PURCHASE_ORDER;
        $data['source_type'] = 'purchase_order';
        return $this->createDraft($data);
    }

    /** Create GRN from inter-warehouse transfer */
    public function receiveFromTransfer(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_TRANSIT_IN;
        $data['source_type'] = 'transfer';
        return $this->createDraft($data);
    }

    /** Create GRN from sales return */
    public function receiveFromSalesReturn(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_SALES_RETURN;
        $data['source_type'] = 'sales_return';
        return $this->createDraft($data);
    }

    /** Create GRN from consignment */
    public function receiveFromConsignment(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_CONSIGNMENT;
        $data['source_type'] = 'consignment';
        return $this->createDraft($data);
    }

    // ─── TAHAP 1: RECEIVE ITEMS ───

    public function receive(int $inboundId, array $data): Inbound
    {
        return DB::transaction(function () use ($inboundId, $data) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if (! $inbound->isReceivable()) {
                throw new \Exception("Inbound sudah berstatus {$inbound->status}, tidak bisa menerima barang.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $itemsDict = $inbound->items->keyBy('id');

            foreach ($data['items'] as $receiptData) {
                $inboundItem = $itemsDict->get($receiptData['inbound_item_id']);
                if (! $inboundItem) {
                    throw new \Exception("Item ID {$receiptData['inbound_item_id']} tidak terkait dengan Inbound ini.");
                }

                $newTotal = $inboundItem->received_qty + $receiptData['qty'];
                if ($newTotal > $inboundItem->expected_qty) {
                    throw new \Exception("Jumlah terima melebihi ekspektasi untuk item {$inboundItem->item_id} (expected: {$inboundItem->expected_qty}, total: {$newTotal}).");
                }

                $this->inboundRepository->createReceipt([
                    'inbound_item_id' => $inboundItem->id,
                    'qty'             => $receiptData['qty'],
                    'bin_id'          => $defaultBin->id,
                    'batch_no'        => $receiptData['batch_no'] ?? null,
                    'serial_no'       => $receiptData['serial_no'] ?? null,
                    'condition'       => $receiptData['condition'] ?? 'GOOD',
                    'received_by'     => $data['received_by'],
                    'received_date'   => now(),
                ]);

                $this->inboundRepository->updateItemReceivedQty($inboundItem->id, $receiptData['qty']);
                $inboundItem->received_qty = $newTotal;

                $this->inventoryService->adjust([
                    'item_id'            => $inboundItem->item_id,
                    'location_id'        => $inbound->location_id,
                    'bin_id'             => $defaultBin->id,
                    'batch_no'           => $receiptData['batch_no'] ?? '',
                    'serial_no'          => $receiptData['serial_no'] ?? '',
                    'qty'                => $receiptData['qty'],
                    'transaction_number' => $inbound->transaction_number,
                    'created_by'         => $data['received_by'],
                ]);
            }

            $allReceived = $inbound->items->every(fn ($item) => $item->received_qty >= $item->expected_qty);
            $newStatus = $allReceived ? Inbound::STATUS_RECEIVED : Inbound::STATUS_PARTIAL;
            $this->inboundRepository->updateStatus($inbound, $newStatus);

            // Record discrepancy if fully received
            if ($allReceived) {
                foreach ($inbound->items as $item) {
                    $disc = $item->expected_qty - $item->received_qty;
                    if ($disc !== 0) {
                        $this->inboundRepository->updateItemDiscrepancy(
                            $item->id,
                            $disc,
                            "Expected {$item->expected_qty}, received {$item->received_qty}"
                        );
                    }
                }
            }

            return $this->getById($inboundId);
        });
    }

    /** Mark discrepancy and close receiving even if partial */
    public function closeReceiving(int $inboundId, string $closedBy): Inbound
    {
        return DB::transaction(function () use ($inboundId, $closedBy) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            $closeable = in_array($inbound->status, [
                Inbound::STATUS_DRAFT,
                Inbound::STATUS_PARTIAL,
                Inbound::STATUS_RECEIVED,
            ]);

            if (! $closeable) {
                throw new \Exception("Inbound sudah berstatus {$inbound->status}.");
            }

            foreach ($inbound->items as $item) {
                $disc = $item->expected_qty - $item->received_qty;
                if ($disc > 0) {
                    $this->inboundRepository->updateItemDiscrepancy(
                        $item->id,
                        $disc,
                        "Closed by {$closedBy}. Expected {$item->expected_qty}, received {$item->received_qty}"
                    );
                }
            }

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_RECEIVED);

            return $this->getById($inboundId);
        });
    }

    // ─── TAHAP 2: PUTAWAY ───

    /** Manual putaway — user specifies destination bins */
    public function processPutaway(int $inboundId, array $data): Inbound
    {
        return DB::transaction(function () use ($inboundId, $data) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if (! $inbound->isPutawayable()) {
                throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-putaway. Harus berstatus RECEIVED terlebih dahulu.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $itemsDict = $inbound->items->keyBy('id');

            foreach ($data['putaway_items'] as $putawayItem) {
                $inboundItem = $itemsDict->get($putawayItem['inbound_item_id']);
                if (! $inboundItem) {
                    throw new \Exception("Item ID {$putawayItem['inbound_item_id']} tidak terkait dengan Inbound ini.");
                }

                $pendingQty = $inboundItem->pendingPutawayQty();
                if ($putawayItem['qty'] > $pendingQty) {
                    throw new \Exception("Qty putaway ({$putawayItem['qty']}) melebihi pending putaway ({$pendingQty}) untuk item {$inboundItem->item_id}.");
                }

                $this->inventoryService->putaway([
                    'item_id'            => $inboundItem->item_id,
                    'location_id'        => $inbound->location_id,
                    'source_bin_id'      => $defaultBin->id,
                    'destination_bin_id' => $putawayItem['destination_bin_id'],
                    'qty'                => $putawayItem['qty'],
                    'batch_no'           => $putawayItem['batch_no'] ?? '',
                    'serial_no'          => $putawayItem['serial_no'] ?? '',
                    'created_by'         => $data['created_by'],
                ]);

                $this->inboundRepository->updateItemPutawayQty($inboundItem->id, $putawayItem['qty']);
                $inboundItem->putaway_qty += $putawayItem['qty'];
            }

            $this->resolveInboundPutawayStatus($inbound);

            return $this->getById($inboundId);
        });
    }

    /** Auto putaway — system assigns bins based on available capacity */
    public function autoPutaway(int $inboundId, string $createdBy): Inbound
    {
        return DB::transaction(function () use ($inboundId, $createdBy) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if (! $inbound->isPutawayable()) {
                throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-putaway.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $pendingItems = $this->inboundRepository->getItemsPendingPutaway($inboundId);
            if ($pendingItems->isEmpty()) {
                throw new \Exception("Tidak ada item yang perlu di-putaway.");
            }

            $availableBins = $this->binService->getByLocation($inbound->location_id)
                ->where('is_inbound', false);

            if ($availableBins->isEmpty()) {
                throw new \Exception("Tidak ada bin tujuan yang tersedia di gudang ini.");
            }

            $firstBin = $availableBins->first();

            foreach ($pendingItems as $item) {
                $pendingQty = $item->pendingPutawayQty();
                if ($pendingQty <= 0) {
                    continue;
                }

                $this->inventoryService->putaway([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'source_bin_id'      => $defaultBin->id,
                    'destination_bin_id' => $firstBin->id,
                    'qty'                => $pendingQty,
                    'batch_no'           => '',
                    'serial_no'          => '',
                    'created_by'         => $createdBy,
                ]);

                $this->inboundRepository->updateItemPutawayQty($item->id, $pendingQty);
            }

            $inbound->load('items');
            $this->resolveInboundPutawayStatus($inbound);

            return $this->getById($inboundId);
        });
    }

    public function getReceivedItemsPaginated(int $limit = 10)
    {
        return $this->inboundRepository->getReceivedItemsPaginated($limit);
    }

    public function getItemsPendingPutaway(int $inboundId)
    {
        return $this->inboundRepository->getItemsPendingPutaway($inboundId);
    }

    // ─── ASSIGNMENT ───

    public function assignWorker(int $inboundId, int $assignedTo, int $assignedBy, ?string $notes = null): InboundAssignment
    {
        $inbound = $this->inboundRepository->findById($inboundId);

        if (! $inbound) {
            throw new \Exception("Dokumen Inbound tidak ditemukan.");
        }

        if ($inbound->status === Inbound::STATUS_CANCELLED || $inbound->status === Inbound::STATUS_COMPLETED) {
            throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-assign.");
        }

        return $this->inboundRepository->createAssignment([
            'inbound_id'  => $inboundId,
            'assigned_to' => $assignedTo,
            'assigned_by' => $assignedBy,
            'status'      => InboundAssignment::STATUS_PENDING,
            'notes'       => $notes,
        ]);
    }

    public function getAssignments(int $inboundId)
    {
        return $this->inboundRepository->getAssignmentsByInbound($inboundId);
    }

    public function getMyAssignments(int $userId, ?string $status = null)
    {
        return $this->inboundRepository->getAssignmentsByWorker($userId, $status);
    }

    public function startAssignment(int $assignmentId, int $userId): InboundAssignment
    {
        $assignment = InboundAssignment::findOrFail($assignmentId);

        if ($assignment->assigned_to !== $userId) {
            throw new \Exception("Assignment ini bukan milik Anda.");
        }

        if ($assignment->status !== InboundAssignment::STATUS_PENDING) {
            throw new \Exception("Assignment sudah berstatus {$assignment->status}.");
        }

        $assignment->update([
            'status'     => InboundAssignment::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $assignment->fresh()->load('inbound.items', 'worker:id,name');
    }

    // ─── QR SCAN ───

    public function lookupByQr(string $uuid): InboundItem
    {
        $item = $this->inboundRepository->findItemByUuid($uuid);

        if (! $item) {
            throw new \Exception("QR Code tidak ditemukan.");
        }

        return $item->load('inbound.location', 'variant:id,sku,product_id');
    }

    public function scanPutaway(string $inboundItemId, string $binId, int $qty, int $userId): InboundItem
    {
        return DB::transaction(function () use ($inboundItemId, $binId, $qty, $userId) {
            $inboundItem = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);

            if (! $inboundItem) {
                throw new \Exception("QR Code barang tidak ditemukan.");
            }

            $destinationBin = \Modules\Warehouse\Models\LocationBin::find($binId);

            if (! $destinationBin) {
                throw new \Exception("QR Code rak tidak ditemukan.");
            }

            $inbound = $inboundItem->inbound;

            if (! $inbound->isPutawayable() && $inbound->status !== Inbound::STATUS_RECEIVED) {
                throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-putaway.");
            }

            $pendingQty = $inboundItem->pendingPutawayQty();
            if ($qty > $pendingQty) {
                throw new \Exception("Qty putaway ({$qty}) melebihi pending putaway ({$pendingQty}).");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $this->inventoryService->putaway([
                'item_id'            => $inboundItem->item_id,
                'location_id'        => $inbound->location_id,
                'source_bin_id'      => $defaultBin->id,
                'destination_bin_id' => $destinationBin->id,
                'qty'                => $qty,
                'batch_no'           => '',
                'serial_no'          => '',
                'created_by'         => "user:{$userId}",
            ]);

            $this->inboundRepository->updateItemPutawayQty($inboundItem->id, $qty);

            $inbound->load('items');
            $this->resolveInboundPutawayStatus($inbound);

            $this->completeAssignmentIfDone($inbound, $userId);

            return $inboundItem->fresh()->load('inbound', 'variant:id,sku,product_id');
        });
    }

    private function completeAssignmentIfDone(Inbound $inbound, int $userId): void
    {
        if ($inbound->status !== Inbound::STATUS_COMPLETED) {
            return;
        }

        $inbound->assignments()
            ->where('assigned_to', $userId)
            ->where('status', InboundAssignment::STATUS_IN_PROGRESS)
            ->update([
                'status'       => InboundAssignment::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
    }

    public function cancel(int $inboundId): Inbound
    {
        return DB::transaction(function () use ($inboundId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if ($inbound->status === Inbound::STATUS_COMPLETED) {
                throw new \Exception("Inbound yang sudah COMPLETED tidak bisa dibatalkan.");
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new \Exception("Inbound sudah dibatalkan.");
            }

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_CANCELLED);

            return $this->getById($inboundId);
        });
    }

    private function resolveInboundPutawayStatus(Inbound $inbound): void
    {
        $allPutaway = $inbound->items->every(fn ($item) => $item->isFullyPutaway());

        $newStatus = $allPutaway
            ? Inbound::STATUS_COMPLETED
            : Inbound::STATUS_PUTAWAY_IN_PROGRESS;

        $this->inboundRepository->updateStatus($inbound, $newStatus);
    }
}
