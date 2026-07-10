<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\PutawayRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Models\PutawaySource;
use Modules\Inventory\Models\PutawayItemSource;
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
                $putawayItem = $this->putawayRepository->createItem([
                    'putaway_id' => $putaway->id,
                    'item_id' => $itemData['item_id'],
                    'source_bin_id' => $itemData['source_bin_id'],
                    'destination_bin_id' => $itemData['destination_bin_id'] ?? null,
                    'qty' => $itemData['qty'],
                    'putaway_qty' => 0,
                    'batch_no' => $itemData['batch_no'] ?? null,
                    'serial_no' => $itemData['serial_no'] ?? null,
                ]);

                foreach ($itemData['sources'] ?? [] as $src) {
                    PutawayItemSource::create([
                        'putaway_item_id' => $putawayItem->id,
                        'inbound_item_id' => $src['inbound_item_id'],
                        'qty' => $src['qty'],
                        'putaway_qty' => 0,
                    ]);
                }
            }

            foreach (array_unique($data['sources'] ?? []) as $inboundId) {
                PutawaySource::create([
                    'putaway_id' => $putaway->id,
                    'inbound_id' => $inboundId,
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

        if ($putaway->status === Putaway::STATUS_NOT_STARTED) {
            $this->putawayRepository->updateStatus($putawayId, Putaway::STATUS_IN_PROGRESS, [
                'started_at' => now(),
            ]);
        } elseif ($putaway->status !== Putaway::STATUS_IN_PROGRESS) {
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

            if ($putaway->source_type === 'INBOUND') {
                foreach ($this->sourceInbounds($putaway) as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }
            }

            return $this->putawayRepository->findById($id);
        });
    }

    public function completeWithDiscrepancy(string $id, string $userId): array
    {
        return DB::transaction(function () use ($id, $userId) {
            $putaway = $this->putawayRepository->findByIdForUpdate($id);

            if (!$putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            if ($putaway->status !== Putaway::STATUS_IN_PROGRESS) {
                throw new \Exception("Hanya putaway IN_PROGRESS yang bisa diselesaikan dengan selisih (status saat ini: {$putaway->status}).");
            }

            $putaway->load('items');
            $incomplete = $putaway->items->filter(fn ($item) => $item->putaway_qty < $item->qty);

            if ($incomplete->isEmpty()) {
                throw new \Exception('Tidak ada selisih pada dokumen ini. Gunakan aksi Selesaikan biasa.');
            }

            $defaultBin = app(\Modules\Warehouse\Services\LocationBinService::class)->getDefaultBin($putaway->location_id);

            if (!$defaultBin) {
                throw new \Exception('Rak default (inbound) untuk lokasi ini tidak ditemukan.');
            }

            $discrepancyItems = [];

            foreach ($incomplete as $item) {
                $remaining = (int) $item->qty - (int) $item->putaway_qty;
                if ($remaining <= 0) {
                    continue;
                }

                $this->processItem($id, $item->id, [
                    'destination_bin_id' => $defaultBin->id,
                    'qty' => $remaining,
                ]);

                $discrepancyItems[] = [
                    'putaway_item_id' => $item->id,
                    'item_id' => $item->item_id,
                    'qty' => $remaining,
                    'bin_id' => $defaultBin->id,
                    'batch_no' => $item->batch_no,
                    'serial_no' => $item->serial_no,
                ];
            }

            $putaway = $this->putawayRepository->findById($id);

            if ($putaway->status !== Putaway::STATUS_COMPLETED) {
                $this->putawayRepository->updateStatus($id, Putaway::STATUS_COMPLETED, [
                    'completed_at' => now(),
                ]);
                $putaway = $this->putawayRepository->findById($id);
            }

            if ($putaway->source_type === 'INBOUND') {
                foreach ($this->sourceInbounds($putaway) as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }
            }

            return [
                'putaway' => $putaway,
                'discrepancy_items' => $discrepancyItems,
            ];
        });
    }

    public function deletePlacement(string $putawayId, string $itemId, string $placementId, ?int $qty, string $userId): Putaway
    {
        return $this->deletePlacements($putawayId, [
            ['item_id' => $itemId, 'placement_id' => $placementId, 'qty' => $qty],
        ], $userId);
    }

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

            if ($putaway->status === Putaway::STATUS_COMPLETED) {
                $this->putawayRepository->updateStatus($putawayId, Putaway::STATUS_IN_PROGRESS, [
                    'completed_at' => null,
                ]);
            }

            if ($putaway->source_type === 'INBOUND') {
                foreach ($this->sourceInbounds($putaway) as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }
            }

            return $this->putawayRepository->findById($putawayId);
        });
    }

    public function deletePutaway(string $id, string $userId): array
    {
        return DB::transaction(function () use ($id, $userId) {
            $putaway = $this->putawayRepository->findByIdForUpdate($id);

            if (! $putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            $status = $putaway->status;

            $hasPlacements = PutawayPlacement::whereIn(
                'putaway_item_id',
                PutawayItem::where('putaway_id', $id)->pluck('id')
            )->exists();

            if ($status === Putaway::STATUS_CANCELLED) {
                throw new \Exception('Putaway yang sudah dibatalkan tidak bisa dihapus.');
            }

            if ($status === Putaway::STATUS_NOT_STARTED) {
                if ($hasPlacements) {
                    $this->resetAllPlacements($putaway, $userId);
                }

                $inbounds = $putaway->source_type === 'INBOUND'
                    ? $this->sourceInbounds($putaway)
                    : collect();

                $this->hardDeletePutaway($putaway);

                foreach ($inbounds as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }

                return ['id' => $id, 'action' => 'unassigned'];
            }

            $this->resetAllPlacements($putaway, $userId);

            $target = $status === Putaway::STATUS_COMPLETED
                ? Putaway::STATUS_IN_PROGRESS
                : Putaway::STATUS_NOT_STARTED;

            $extra = $status === Putaway::STATUS_COMPLETED
                ? ['completed_at' => null]
                : ['started_at' => null, 'completed_at' => null];

            $this->putawayRepository->updateStatus($id, $target, $extra);

            if ($putaway->source_type === 'INBOUND') {
                foreach ($this->sourceInbounds($putaway) as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }
            }

            return [
                'id' => $id,
                'action' => $status === Putaway::STATUS_COMPLETED ? 'reset_in_progress' : 'reset_not_started',
            ];
        });
    }

    public function bulkDeletePutaway(array $ids, string $userId): array
    {
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            throw new \Exception('Tidak ada penempatan yang dipilih untuk dihapus.');
        }

        return DB::transaction(function () use ($ids, $userId) {
            $results = [];
            foreach ($ids as $id) {
                $results[] = $this->deletePutaway($id, $userId);
            }
            return $results;
        });
    }

    private function resetAllPlacements(Putaway $putaway, string $userId): void
    {
        $putaway->load(['items.placements']);

        foreach ($putaway->items as $item) {
            foreach ($item->placements as $placement) {
                $this->reverseOnePlacement($putaway, [
                    'item_id'      => $item->id,
                    'placement_id' => $placement->id,
                    'qty'          => null,
                ], $userId);
            }
        }
    }

    private function hardDeletePutaway(Putaway $putaway): void
    {
        $itemIds = PutawayItem::where('putaway_id', $putaway->id)->pluck('id');

        PutawayItemSource::whereIn('putaway_item_id', $itemIds)->delete();
        PutawayPlacement::whereIn('putaway_item_id', $itemIds)->delete();
        PutawayItem::where('putaway_id', $putaway->id)->delete();
        PutawaySource::where('putaway_id', $putaway->id)->delete();

        $putaway->delete();
    }

    public function getManyForPdf(array $ids)
    {
        return $this->putawayRepository->getManyWithDetails($ids);
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

        if ($qtyRev >= (int) $placement->qty) {
            $placement->delete();
        } else {
            $placement->decrement('qty', $qtyRev);
        }

        $newPutawayQty = max(0, (int) $item->putaway_qty - $qtyRev);
        $item->putaway_qty = $newPutawayQty;
        if ($newPutawayQty === 0) {
            $item->destination_bin_id = null;
        }
        $item->save();

        if ($putaway->source_type === 'INBOUND') {
            $sources = PutawayItemSource::query()
                ->where('putaway_item_sources.putaway_item_id', $item->id)
                ->join('inbound_items', 'inbound_items.id', '=', 'putaway_item_sources.inbound_item_id')
                ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
                ->orderByDesc('inbounds.created_at') 
                ->lockForUpdate()
                ->select('putaway_item_sources.*')
                ->get();

            if ($sources->isNotEmpty()) {
                $remaining = $qtyRev;
                foreach ($sources as $src) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min((int) $src->putaway_qty, $remaining);
                    if ($take <= 0) {
                        continue;
                    }
                    $src->decrement('putaway_qty', $take);
                    InboundItem::where('id', $src->inbound_item_id)->decrement('putaway_qty', $take);
                    $remaining -= $take;
                }
            } elseif ($putaway->source_id) {

                $inboundItem = InboundItem::where('inbound_id', $putaway->source_id)
                    ->where('item_id', $item->item_id)
                    ->first();

                if ($inboundItem) {
                    $inboundItem->decrement('putaway_qty', min($qtyRev, (int) $inboundItem->putaway_qty));
                }
            }
        }
    }

    private function sourceInbounds(Putaway $putaway)
    {
        $ids = $putaway->sourceRows()->pluck('inbound_id');

        if ($ids->isEmpty() && $putaway->source_id) {
            $ids = collect([$putaway->source_id]);
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        return Inbound::with('items')->whereIn('id', $ids->all())->get();
    }

    private function recomputeInboundStatus(Inbound $inbound): void
    {
        $inbound->loadMissing('items');

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
            $inbound->update(['status' => $newStatus]);
        }
    }

    public function reduceOpenTargetForInboundItem(string $inboundItemId, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $sources = PutawayItemSource::where('inbound_item_id', $inboundItemId)
            ->whereHas('putawayItem.putaway', function ($q) {
                $q->whereNotIn('status', [Putaway::STATUS_COMPLETED, Putaway::STATUS_CANCELLED]);
            })
            ->with('putawayItem.putaway')
            ->lockForUpdate()
            ->get();

        $remaining = $amount;

        foreach ($sources as $src) {
            if ($remaining <= 0) {
                break;
            }

            $srcUnplaced = (int) $src->qty - (int) $src->putaway_qty;
            if ($srcUnplaced <= 0) {
                continue;
            }

            $take = min($srcUnplaced, $remaining);

            $src->qty = (int) $src->qty - $take;
            $src->save();

            $item = $src->putawayItem;
            if ($item) {
                $item->qty = max((int) $item->putaway_qty, (int) $item->qty - $take);
                $item->save();

                $putaway = $item->putaway;
                if ($putaway
                    && $putaway->status === Putaway::STATUS_IN_PROGRESS
                    && $putaway->items()->get()->every(fn ($i) => (int) $i->putaway_qty >= (int) $i->qty)) {
                    $this->putawayRepository->updateStatus($putaway->id, Putaway::STATUS_COMPLETED, [
                        'completed_at' => now(),
                    ]);
                }
            }

            $remaining -= $take;
        }

        return $amount - $remaining;
    }
}
