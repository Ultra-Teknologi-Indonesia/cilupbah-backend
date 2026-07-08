<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Repositories\InboundRepository;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundAssignment;
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\PutawayService;
use Modules\Notification\Events\TaskAssigned;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Warehouse\Services\LocationBinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboundService
{
    public function __construct(
        protected InboundRepository $inboundRepository,
        protected InventoryService $inventoryService,
        protected LocationBinService $binService,
        protected PutawayService $putawayService,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->inboundRepository->getAllPaginated($limit);
    }

    public function getPaginatedItems(string $inboundId, int $perPage = 10)
    {
        return $this->inboundRepository->getPaginatedItems($inboundId, $perPage);
    }

    public function getById(string $id): ?Inbound
    {
        return $this->inboundRepository->findById($id);
    }

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

    public function receiveFromPO(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_PURCHASE_ORDER;
        $data['source_type'] = 'purchase_order';

        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['transaction_number'] = $data['reference_number'] ?? ('INB-' . Str::upper(Str::random(8)));
            $data['status'] = Inbound::STATUS_DRAFT;

            $inbound = $this->inboundRepository->create($data);

            $receiveItems = [];
            foreach ($items as $itemData) {
                $acceptedQty = $itemData['accepted_qty'] ?? $itemData['expected_qty'];
                $rejectedQty = $itemData['rejected_qty'] ?? 0;

                $inboundItem = $this->inboundRepository->createItem([
                    'inbound_id'     => $inbound->id,
                    'item_id'        => $itemData['item_id'],
                    'expected_qty'   => $itemData['expected_qty'],
                    'received_qty'   => 0,
                    'rejected_qty'   => $rejectedQty,
                    'rejection_note' => $itemData['rejection_note'] ?? null,
                    'condition'      => $rejectedQty > 0 ? 'PARTIAL' : 'GOOD',
                    'notes'          => $itemData['notes'] ?? null,
                ]);

                if ($acceptedQty > 0) {
                    $receiveItems[] = [
                        'inbound_item_id' => $inboundItem->id,
                        'qty'             => $acceptedQty,
                        'condition'       => 'GOOD',
                    ];
                }
            }

            if (empty($receiveItems)) {
                $inbound->update(['status' => Inbound::STATUS_RECEIVED]);
                return $inbound->load('items');
            }

            return $this->receive($inbound->id, [
                'received_by' => $data['created_by'],
                'items'       => $receiveItems,
            ]);
        });
    }

    public function receiveFromTransfer(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_TRANSIT_IN;
        $data['source_type'] = 'transfer';

        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['transaction_number'] = $data['reference_number'] ?? ('INB-' . Str::upper(Str::random(8)));

            $data['status'] = Inbound::STATUS_RECEIVED;

            $inbound = $this->inboundRepository->create($data);

            foreach ($items as $itemData) {
                $this->inboundRepository->createItem([
                    'inbound_id'   => $inbound->id,
                    'item_id'      => $itemData['item_id'],
                    'expected_qty' => $itemData['expected_qty'],
                    'received_qty' => $itemData['expected_qty'],
                ]);
            }

            return $inbound->load('items');
        });
    }

    public function receiveFromSalesReturn(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_SALES_RETURN;
        $data['source_type'] = 'sales_return';
        return $this->createDraft($data);
    }

    public function receiveFromConsignment(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_CONSIGNMENT;
        $data['source_type'] = 'consignment';
        return $this->createDraft($data);
    }

    public function receive(string $inboundId, array $data): Inbound
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

            $landedCostMap = $this->resolveLandedCostMap($inbound);

            foreach ($data['items'] as $receiptData) {
                $inboundItem = $itemsDict->get($receiptData['inbound_item_id']);
                if (! $inboundItem) {
                    throw new \Exception("Item ID {$receiptData['inbound_item_id']} tidak terkait dengan Inbound ini.");
                }

                $condition = $receiptData['condition'] ?? 'GOOD';
                $isDamage = $condition === 'DAMAGE';

                $currentTotal = $inboundItem->received_qty + ($inboundItem->rejected_qty ?? 0);
                $newTotal = $currentTotal + $receiptData['qty'];
                if ($newTotal > $inboundItem->expected_qty) {
                    throw new \Exception("Jumlah terima melebihi ekspektasi untuk item {$inboundItem->item_id} (expected: {$inboundItem->expected_qty}, total: {$newTotal}).");
                }

                $this->inboundRepository->createReceipt([
                    'inbound_item_id' => $inboundItem->id,
                    'qty'             => $receiptData['qty'],
                    'bin_id'          => $defaultBin->id,
                    'batch_no'        => $receiptData['batch_no'] ?? null,
                    'serial_no'       => $receiptData['serial_no'] ?? null,
                    'condition'       => $condition,
                    'received_by'     => $data['received_by'],
                    'received_date'   => now(),
                ]);

                if ($isDamage) {
                    $this->inboundRepository->updateItemRejectedQty($inboundItem->id, $receiptData['qty']);
                    $inboundItem->rejected_qty = ($inboundItem->rejected_qty ?? 0) + $receiptData['qty'];
                } else {
                    $this->inboundRepository->updateItemReceivedQty($inboundItem->id, $receiptData['qty']);
                    $inboundItem->received_qty += $receiptData['qty'];
                }

                if (! $isDamage) {
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

                $landedCost = (float) ($landedCostMap[$inboundItem->item_id] ?? 0);
                if ($landedCost > 0 && ! $isDamage) {
                    $this->inventoryService->recalculateAverageCost(
                        $inboundItem->item_id,
                        $inbound->location_id,
                        $defaultBin->id,
                        (float) $receiptData['qty'],
                        $landedCost,
                        $receiptData['batch_no'] ?? '',
                        $receiptData['serial_no'] ?? '',
                    );

                    InventoryMovement::where('transaction_number', $inbound->transaction_number)
                        ->where('item_id', $inboundItem->item_id)
                        ->where('location_id', $inbound->location_id)
                        ->where('bin_id', $defaultBin->id)
                        ->where('qty', $receiptData['qty'])
                        ->whereNull('cost_per_unit')
                        ->orderByDesc('id')
                        ->limit(1)
                        ->update([
                            'cost_per_unit' => $landedCost,
                            'total_cost'    => round($landedCost * (float) $receiptData['qty'], 2),
                        ]);
                }
            }

            $allReceived = $inbound->items->every(fn ($item) => $item->isFullyReceived());
            $newStatus = $allReceived ? Inbound::STATUS_RECEIVED : Inbound::STATUS_PARTIAL;
            $this->inboundRepository->updateStatus($inbound, $newStatus);

            if ($allReceived) {
                foreach ($inbound->items as $item) {
                    $disc = $item->expected_qty - $item->received_qty - ($item->rejected_qty ?? 0);
                    if ($disc !== 0) {
                        $this->inboundRepository->updateItemDiscrepancy(
                            $item->id,
                            $disc,
                            "Expected {$item->expected_qty}, received {$item->received_qty}"
                        );
                    }
                }

                $inbound->assignments()
                    ->where('status', InboundAssignment::STATUS_IN_PROGRESS)
                    ->update([
                        'status'       => InboundAssignment::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
            }

            return $this->getById($inboundId);
        });
    }

    public function closeReceiving(string $inboundId, string $closedBy): Inbound
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
                $disc = $item->expected_qty - $item->received_qty - ($item->rejected_qty ?? 0);
                if ($disc > 0) {
                    $this->inboundRepository->updateItemDiscrepancy(
                        $item->id,
                        $disc,
                        "Closed by {$closedBy}. Expected {$item->expected_qty}, received {$item->received_qty}, rejected {$item->rejected_qty}"
                    );
                }
            }

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_RECEIVED);

            return $this->getById($inboundId);
        });
    }

    public function processPutaway(string $inboundId, array $data): Inbound
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

    public function autoPutaway(string $inboundId, string $createdBy): Inbound
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

    public function getItemsPendingPutaway(string $inboundId)
    {
        return $this->inboundRepository->getItemsPendingPutaway($inboundId);
    }

    public function assignWorker(string $inboundId, string $assignedTo, string $assignedBy, ?string $notes = null): InboundAssignment
    {
        $inbound = $this->inboundRepository->findById($inboundId);

        if (! $inbound) {
            throw new \Exception("Dokumen Inbound tidak ditemukan.");
        }

        if ($inbound->status === Inbound::STATUS_CANCELLED || $inbound->status === Inbound::STATUS_COMPLETED) {
            throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-assign.");
        }

        $assignment = $this->inboundRepository->createAssignment([
            'inbound_id'  => $inboundId,
            'assigned_to' => $assignedTo,
            'assigned_by' => $assignedBy,
            'status'      => InboundAssignment::STATUS_PENDING,
            'notes'       => $notes,
        ]);

        TaskAssigned::dispatch(
            $assignedTo,
            'inbound',
            $inbound->transaction_number,
            $assignedBy,
            ['inbound_id' => $inboundId],
        );

        return $assignment;
    }

    public function getAssignments(string $inboundId)
    {
        return $this->inboundRepository->getAssignmentsByInbound($inboundId);
    }

    public function getMyAssignments(string $userId, ?string $status = null, int $perPage = 10, ?string $search = null, string $sort = '-created_at')
    {
        return $this->inboundRepository->getAssignmentsByWorker($userId, $status, $perPage, $search, $sort);
    }

    public function startAssignment(string $assignmentId, string $userId): InboundAssignment
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

    public function lookupByQr(string $uuid): InboundItem
    {
        $item = $this->inboundRepository->findItemByUuid($uuid);

        if (! $item) {
            throw new \Exception("QR Code tidak ditemukan.");
        }

        return $item->load('inbound.location', 'variant:id,sku,product_id');
    }

    public function scanPutaway(string $inboundItemId, string $binId, int $qty, string $userId): InboundItem
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

    private function completeAssignmentIfDone(Inbound $inbound, string $userId): void
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

    /**
     * Koreksi salah terima (received_qty berlebih) pada satu baris inbound.
     * Mengurangi received_qty dan mengembalikan stok di bin inbound. Hanya stok yang
     * belum di-putaway yang bisa dikoreksi.
     */
    public function correctReceivedLine(string $inboundId, string $inboundItemId, ?int $qty, string $userId): Inbound
    {
        return $this->correctReceivedLines($inboundId, [
            ['item_id' => $inboundItemId, 'qty' => $qty],
        ], $userId);
    }

    /**
     * Koreksi massal salah terima: kurangi received_qty beberapa baris sekaligus dalam 1 transaksi.
     * $items = [['item_id' => <inbound_item_uuid>, 'qty' => ?int], ...]
     */
    public function correctReceivedLines(string $inboundId, array $items, string $userId): Inbound
    {
        if (empty($items)) {
            throw new \Exception('Tidak ada baris yang dipilih untuk dikoreksi.');
        }

        return DB::transaction(function () use ($inboundId, $items, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new \Exception("Inbound sudah dibatalkan.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            foreach ($items as $entry) {
                $inboundItemId = $entry['item_id'] ?? null;
                $qty = $entry['qty'] ?? null;

                if (! $inboundItemId) {
                    throw new \Exception('item_id wajib diisi.');
                }

                $item = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);

                if (! $item || $item->inbound_id !== $inbound->id) {
                    throw new \Exception("Item inbound tidak ditemukan.");
                }

                $qtyRev = $qty ?? (int) $item->received_qty;

                if ($qtyRev <= 0 || $qtyRev > (int) $item->received_qty) {
                    throw new \Exception("Qty koreksi tidak valid (maksimal {$item->received_qty}).");
                }

                $available = (int) $item->received_qty - (int) $item->putaway_qty;
                if ($qtyRev > $available) {
                    throw new \Exception("Sebagian barang sudah di-putaway; hanya {$available} unit yang masih di bin inbound dan bisa dikoreksi.");
                }

                $this->inventoryService->adjust([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'bin_id'             => $defaultBin->id,
                    'qty'                => -$qtyRev,
                    'transaction_number' => $inbound->transaction_number . '-KOREKSI',
                    'created_by'         => "user:{$userId}",
                ]);

                $this->inboundRepository->updateItemReceivedQty($item->id, -$qtyRev);
            }

            return $this->getById($inboundId);
        });
    }

    /**
     * Set jumlah diterima aktual pada satu baris penerimaan (boleh naik/turun).
     * - Menyesuaikan stok di Bin Inbound lewat movement (naik = tambah, turun = kurang),
     *   sehingga perubahan TERCATAT sebagai riwayat dengan created_by = pelaku.
     * - Bila turun, target penempatan yang belum ditempatkan ikut disusutkan.
     * - Status penerimaan dihitung ulang.
     * Tidak ada konsep "selisih": angka tinggal diedit, sistem merapikan sisanya.
     */
    public function setReceivedQty(string $inboundId, string $inboundItemId, int $targetQty, string $userId): Inbound
    {
        if ($targetQty < 0) {
            throw new \Exception('Jumlah tidak boleh negatif.');
        }

        return DB::transaction(function () use ($inboundId, $inboundItemId, $targetQty, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new \Exception('Dokumen Inbound tidak ditemukan.');
            }
            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new \Exception('Inbound sudah dibatalkan.');
            }

            $item = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);
            if (! $item || $item->inbound_id !== $inbound->id) {
                throw new \Exception('Item inbound tidak ditemukan.');
            }

            $current = (int) $item->received_qty;
            $delta = $targetQty - $current;
            if ($delta === 0) {
                return $this->getById($inboundId);
            }

            // Menurunkan di bawah yang sudah ditempatkan ke rak tidak mungkin tanpa
            // menarik stok keluar dari rak — arahkan user membatalkan penempatan dulu.
            if ($targetQty < (int) $item->putaway_qty) {
                throw new \Exception("Jumlah diterima tidak bisa di bawah yang sudah ditempatkan ke rak ({$item->putaway_qty}). Batalkan/kurangi penempatan dulu.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception('Gudang ini belum memiliki Bin Inbound default.');
            }

            // Sesuaikan stok Bin Inbound (+ naik / − turun). Movement mencatat pelaku.
            $this->inventoryService->adjust([
                'item_id'            => $item->item_id,
                'location_id'        => $inbound->location_id,
                'bin_id'             => $defaultBin->id,
                'qty'                => $delta,
                'transaction_number' => $inbound->transaction_number . '-EDIT-QTY',
                'created_by'         => "user:{$userId}",
            ]);

            $this->inboundRepository->updateItemReceivedQty($item->id, $delta);

            // Turun → susutkan target penempatan yang belum ditempatkan.
            if ($delta < 0) {
                $this->putawayService->reduceOpenTargetForInboundItem($item->id, -$delta);
            }

            $this->recomputeStatus($inbound->fresh('items'));

            return $this->getById($inboundId);
        });
    }

    /**
     * Hitung ulang status penerimaan dari progres putaway itemnya.
     * Semua tuntas → COMPLETED; ada sebagian → PUTAWAY_IN_PROGRESS; nol → RECEIVED.
     * DRAFT/CANCELLED tidak disentuh.
     */
    private function recomputeStatus(Inbound $inbound): void
    {
        if ($inbound->items->isEmpty()
            || in_array($inbound->status, [Inbound::STATUS_DRAFT, Inbound::STATUS_CANCELLED], true)) {
            return;
        }

        $allPutaway = $inbound->items->every(fn ($i) => $i->isFullyPutaway());
        $anyPutaway = $inbound->items->contains(fn ($i) => (int) $i->putaway_qty > 0);

        $newStatus = $allPutaway
            ? Inbound::STATUS_COMPLETED
            : ($anyPutaway ? Inbound::STATUS_PUTAWAY_IN_PROGRESS : Inbound::STATUS_RECEIVED);

        if ($inbound->status !== $newStatus) {
            $this->inboundRepository->updateStatus($inbound, $newStatus);
        }
    }

    public function cancel(string $inboundId, ?string $userId = null): Inbound
    {
        return DB::transaction(function () use ($inboundId, $userId) {
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

            $this->reverseReceivedStock($inbound, $userId);

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_CANCELLED);

            return $this->getById($inboundId);
        });
    }

    /**
     * Batalkan (hapus) beberapa penerimaan sekaligus. Tiap dokumen dibatalkan di
     * transaksinya sendiri; yang gagal dikumpulkan tanpa menggagalkan yang lain.
     */
    public function cancelMany(array $ids, ?string $userId = null): array
    {
        $result = ['cancelled' => [], 'failed' => []];

        foreach ($ids as $id) {
            try {
                $this->cancel($id, $userId);
                $result['cancelled'][] = $id;
            } catch (\Throwable $e) {
                $result['failed'][] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    private function reverseReceivedStock(Inbound $inbound, ?string $userId = null): void
    {
        $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
        $createdBy = $userId ? "user:{$userId}" : 'system';

        foreach ($inbound->items as $item) {
            if ($item->received_qty <= 0) {
                continue;
            }

            $reverseQty = $item->received_qty - $item->putaway_qty;
            if ($reverseQty > 0 && $defaultBin) {
                $this->inventoryService->adjust([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'bin_id'             => $defaultBin->id,
                    'qty'                => -$reverseQty,
                    'transaction_number' => $inbound->transaction_number . '-CANCEL',
                    'created_by'         => $createdBy,
                ]);
            }
        }
    }

    public function downloadBarcodes(string $id)
    {
        $inbound = Inbound::with(['items.variant'])->findOrFail($id);
        
        $pages = [];
        foreach ($inbound->items as $item) {
            $qty = $item->expected_qty > 0 ? $item->expected_qty : ($item->received_qty > 0 ? $item->received_qty : 1);
            
            for ($i = 0; $i < $qty; $i++) {
                $pages[] = [
                    'sku' => $item->variant->sku ?? 'UNKNOWN',
                    'name' => $item->variant->name ?? 'Unknown Product',
                ];
            }
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('inbound::pdf.barcodes', compact('pages'))
            ->setPaper([0, 0, 141.73, 85.04], 'landscape');
            
        return $pdf->stream("barcodes-inbound-{$inbound->transaction_number}.pdf");
    }

    private function createPutawayFromInbound(Inbound $inbound, $defaultBin, string $receivedBy): void
    {
        $items = $inbound->items
            ->filter(fn ($item) => $item->received_qty > 0)
            ->map(fn ($item) => [
                'item_id'            => $item->item_id,
                'source_bin_id'      => $defaultBin->id,
                'destination_bin_id' => null,
                'qty'                => $item->received_qty,
                'batch_no'           => null,
                'serial_no'          => null,
            ])
            ->values()
            ->toArray();

        if (empty($items)) {
            return;
        }

        $this->putawayService->create([
            'location_id' => $inbound->location_id,
            'source_type' => 'INBOUND',
            'source_id'   => $inbound->id,
            'notes'       => "Auto-generated from Inbound {$inbound->transaction_number}",
            'created_by'  => $receivedBy,
            'items'       => $items,
        ]);
    }

    private function resolveLandedCostMap(Inbound $inbound): array
    {
        if ($inbound->source_type === 'sales_return') {
            return $this->resolveSalesReturnCostMap($inbound);
        }

        if ($inbound->source_type !== 'purchase_order' || empty($inbound->source_id)) {
            return [];
        }

        $items = PurchaseOrderItem::where('purchase_order_id', $inbound->source_id)->get();
        if ($items->isEmpty()) {
            return [];
        }

        $map = [];
        foreach ($items->groupBy('item_id') as $itemId => $rows) {
            $totalQty = 0.0;
            $totalCost = 0.0;
            foreach ($rows as $row) {
                $qty = (float) $row->qty;
                if ($qty <= 0) continue;
                $totalQty += $qty;
                $totalCost += $qty * (float) $row->landed_cost_per_unit;
            }
            $map[$itemId] = $totalQty > 0 ? $totalCost / $totalQty : 0;
        }

        return $map;
    }

    private function resolveSalesReturnCostMap(Inbound $inbound): array
    {
        $itemIds = $inbound->items->pluck('item_id')->filter()->unique()->values();
        if ($itemIds->isEmpty()) {
            return [];
        }

        $rows = Inventory::whereIn('item_id', $itemIds)
            ->where('avg_cost', '>', 0)
            ->get(['item_id', 'location_id', 'on_hand', 'avg_cost']);

        $map = [];
        foreach ($rows->groupBy('item_id') as $itemId => $group) {
            $preferred = $group->where('location_id', $inbound->location_id);
            $pool = $preferred->isNotEmpty() ? $preferred : $group;

            $totalQty = 0.0;
            $totalValue = 0.0;
            foreach ($pool as $row) {
                $qty = max((float) $row->on_hand, 0.0);
                $totalQty += $qty;
                $totalValue += $qty * (float) $row->avg_cost;
            }

            $map[$itemId] = $totalQty > 0
                ? $totalValue / $totalQty
                : (float) $pool->avg('avg_cost');
        }

        return $map;
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
