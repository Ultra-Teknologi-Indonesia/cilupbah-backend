<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\PutawayRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Services\InventoryService;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Jobs\ProcessPutawayItemJob;
use Modules\Notification\Events\TaskAssigned;
use Illuminate\Support\Facades\DB;

class PutawayService
{
    public function __construct(
        protected PutawayRepository $putawayRepository,
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
        protected InventoryService $inventoryService,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->putawayRepository->getAllPaginated($limit);
    }

    public function getByStatus(string $status, int $limit = 10)
    {
        return $this->putawayRepository->getByStatus($status, $limit);
    }

    public function getById(string $id): ?Putaway
    {
        return $this->putawayRepository->findById($id);
    }

    public function getItems(string $putawayId, int $limit = 10)
    {
        $putaway = $this->putawayRepository->findById($putawayId);

        if (!$putaway) {
            throw new \Exception('Putaway tidak ditemukan.');
        }

        return $this->putawayRepository->getItemsPaginated($putawayId, $limit);
    }

    public function create(array $data): Putaway
    {
        return DB::transaction(function () use ($data) {
            $putawayNo = $this->putawayRepository->generatePutawayNo();

            $putaway = $this->putawayRepository->create([
                'putaway_no' => $putawayNo,
                'location_id' => $data['location_id'],
                'source_type' => $data['source_type'] ?? 'MANUAL',
                'source_id' => $data['source_id'] ?? null,
                'status' => Putaway::STATUS_NOT_STARTED,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $this->putawayRepository->createItem([
                    'putaway_id' => $putaway->id,
                    'item_id' => $itemData['item_id'],
                    'source_bin_id' => $itemData['source_bin_id'],
                    'destination_bin_id' => $itemData['destination_bin_id'] ?? null,
                    'qty' => $itemData['qty'],
                    'putaway_qty' => 0,
                    'batch_no' => $itemData['batch_no'] ?? null,
                    'serial_no' => $itemData['serial_no'] ?? null,
                ]);
            }

            return $this->putawayRepository->findById($putaway->id);
        });
    }

    public function assignStaff(array $data): array
    {
        $results = [];

        foreach ($data['data'] as $assignment) {
            $putaway = $this->putawayRepository->findByIdForUpdate($assignment['putaway_id']);

            if (!$putaway) {
                throw new \Exception("Putaway {$assignment['putaway_id']} tidak ditemukan.");
            }

            if ($putaway->status === Putaway::STATUS_COMPLETED || $putaway->status === Putaway::STATUS_CANCELLED) {
                throw new \Exception("Putaway {$putaway->putaway_no} sudah {$putaway->status}, tidak bisa di-assign.");
            }

            $putaway->update([
                'assigned_to' => $assignment['assigned_to'],
                'assigned_by' => $data['performed_by'],
            ]);

            $putaway = $this->putawayRepository->findById($assignment['putaway_id']);

            TaskAssigned::dispatch(
                $assignment['assigned_to'],
                'putaway',
                $putaway->putaway_no,
                $data['performed_by'],
                ['putaway_id' => $assignment['putaway_id']],
            );

            $results[] = $putaway;
        }

        return $results;
    }

    public function start(string $id): Putaway
    {
        return DB::transaction(function () use ($id) {
            $putaway = $this->putawayRepository->findByIdForUpdate($id);

            if (!$putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            if ($putaway->status !== Putaway::STATUS_NOT_STARTED) {
                throw new \Exception("Hanya putaway NOT_STARTED yang bisa di-start (status saat ini: {$putaway->status}).");
            }

            $this->putawayRepository->updateStatus($id, Putaway::STATUS_IN_PROGRESS, [
                'started_at' => now(),
            ]);

            return $this->putawayRepository->findById($id);
        });
    }

    public function processItem(string $putawayId, string $itemId, array $data): void
    {
        $putaway = $this->putawayRepository->findById($putawayId);

        if (!$putaway) {
            throw new \Exception('Putaway tidak ditemukan.');
        }

        if ($putaway->status !== Putaway::STATUS_IN_PROGRESS) {
            throw new \Exception("Putaway harus IN_PROGRESS untuk memproses item (status saat ini: {$putaway->status}).");
        }

        ProcessPutawayItemJob::dispatchSync($putawayId, $itemId, $data);
    }

    public function complete(string $id): Putaway
    {
        return DB::transaction(function () use ($id) {
            $putaway = $this->putawayRepository->findByIdForUpdate($id);

            if (!$putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            if ($putaway->status !== Putaway::STATUS_IN_PROGRESS) {
                throw new \Exception("Hanya putaway IN_PROGRESS yang bisa di-complete (status saat ini: {$putaway->status}).");
            }

            $putaway->load('items');
            $incomplete = $putaway->items->filter(fn ($item) => $item->putaway_qty < $item->qty);

            if ($incomplete->isNotEmpty()) {
                throw new \Exception('Masih ada item yang belum selesai di-putaway.');
            }

            $this->putawayRepository->updateStatus($id, Putaway::STATUS_COMPLETED, [
                'completed_at' => now(),
            ]);

            if ($putaway->source_type === 'INBOUND' && $putaway->source_id) {
                $inbound = \Modules\Inbound\Models\Inbound::with('items')->find($putaway->source_id);
                if ($inbound) {
                    $allPutaway = $inbound->items->every(fn ($item) => $item->isFullyPutaway());
                    $inbound->update([
                        'status' => $allPutaway
                            ? \Modules\Inbound\Models\Inbound::STATUS_COMPLETED
                            : \Modules\Inbound\Models\Inbound::STATUS_PUTAWAY_IN_PROGRESS,
                    ]);
                }
            }

            return $this->putawayRepository->findById($id);
        });
    }

    /**
     * Koreksi salah scan penempatan: hapus 1 baris placement (atau sebagian qty-nya)
     * dan kembalikan stok dari rak tujuan ke rak asal.
     */
    public function deletePlacement(string $putawayId, string $itemId, string $placementId, ?int $qty, string $userId): Putaway
    {
        return $this->deletePlacements($putawayId, [
            ['item_id' => $itemId, 'placement_id' => $placementId, 'qty' => $qty],
        ], $userId);
    }

    /**
     * Koreksi massal: hapus beberapa placement sekaligus dalam 1 transaksi (1 hit API).
     * $items = [['item_id' => ..., 'placement_id' => ..., 'qty' => ?int], ...]
     */
    public function deletePlacements(string $putawayId, array $items, string $userId): Putaway
    {
        if (empty($items)) {
            throw new \Exception('Tidak ada penempatan yang dipilih untuk dikoreksi.');
        }

        return DB::transaction(function () use ($putawayId, $items, $userId) {
            $putaway = $this->putawayRepository->findByIdForUpdate($putawayId);

            if (! $putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            if (! in_array($putaway->status, [Putaway::STATUS_IN_PROGRESS, Putaway::STATUS_COMPLETED], true)) {
                throw new \Exception("Hanya putaway IN_PROGRESS atau COMPLETED yang bisa dikoreksi (status saat ini: {$putaway->status}).");
            }

            foreach ($items as $entry) {
                $this->reverseOnePlacement($putaway, $entry, $userId);
            }

            // Revert status putaway bila sebelumnya COMPLETED (kini ada item yang belum penuh).
            if ($putaway->status === Putaway::STATUS_COMPLETED) {
                $this->putawayRepository->updateStatus($putawayId, Putaway::STATUS_IN_PROGRESS, [
                    'completed_at' => null,
                ]);
            }

            // Revert status inbound turunan (hitung sekali setelah semua item diproses).
            if ($putaway->source_type === 'INBOUND' && $putaway->source_id) {
                $inbound = Inbound::with('items')->find($putaway->source_id);
                if ($inbound && $inbound->status === Inbound::STATUS_COMPLETED) {
                    $allPutaway = $inbound->items->every(fn ($i) => $i->isFullyPutaway());
                    if (! $allPutaway) {
                        $inbound->update(['status' => Inbound::STATUS_PUTAWAY_IN_PROGRESS]);
                    }
                }
            }

            return $this->putawayRepository->findById($putawayId);
        });
    }

    private function reverseOnePlacement(Putaway $putaway, array $entry, string $userId): void
    {
        $itemId = $entry['item_id'] ?? null;
        $placementId = $entry['placement_id'] ?? null;
        $qty = $entry['qty'] ?? null;

        if (! $itemId || ! $placementId) {
            throw new \Exception('item_id dan placement_id wajib diisi.');
        }

        $item = $this->putawayRepository->findItemForUpdate($putaway->id, $itemId);

        if (! $item) {
            throw new \Exception('Item putaway tidak ditemukan.');
        }

        if (empty($item->source_bin_id)) {
            throw new \Exception('Item putaway tidak punya rak asal, tidak bisa dikoreksi.');
        }

        $placement = PutawayPlacement::where('id', $placementId)
            ->where('putaway_item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if (! $placement) {
            throw new \Exception('Penempatan tidak ditemukan.');
        }

        $qtyRev = $qty ?? (int) $placement->qty;

        if ($qtyRev <= 0 || $qtyRev > (int) $placement->qty) {
            throw new \Exception("Qty koreksi tidak valid (maksimal {$placement->qty}).");
        }

        // Balik stok: dari rak tujuan (placement->bin_id) kembali ke rak asal (item->source_bin_id).
        $this->inventoryService->reverseBinMove([
            'item_id'            => $item->item_id,
            'location_id'        => $putaway->location_id,
            'from_bin_id'        => $placement->bin_id,
            'to_bin_id'          => $item->source_bin_id,
            'qty'                => $qtyRev,
            'batch_no'           => $item->batch_no ?? '',
            'serial_no'          => $item->serial_no ?? '',
            'source'             => 'PUTAWAY_REVERSAL',
            'transaction_number' => $putaway->putaway_no . '-KOREKSI',
            'created_by'         => "user:{$userId}",
        ]);

        // Kurangi qty placement / hapus baris bila habis.
        if ($qtyRev >= (int) $placement->qty) {
            $placement->delete();
        } else {
            $placement->decrement('qty', $qtyRev);
        }

        // Kurangi putaway_qty item.
        $newPutawayQty = max(0, (int) $item->putaway_qty - $qtyRev);
        $item->putaway_qty = $newPutawayQty;
        if ($newPutawayQty === 0) {
            $item->destination_bin_id = null;
        }
        $item->save();

        // Sinkron ke inbound bila sumbernya INBOUND.
        if ($putaway->source_type === 'INBOUND' && $putaway->source_id) {
            $inboundItem = InboundItem::where('inbound_id', $putaway->source_id)
                ->where('item_id', $item->item_id)
                ->first();

            if ($inboundItem) {
                $inboundItem->decrement('putaway_qty', min($qtyRev, (int) $inboundItem->putaway_qty));
            }
        }
    }
}
