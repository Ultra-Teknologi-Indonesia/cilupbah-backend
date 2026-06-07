<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\PutawayRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Jobs\ProcessPutawayItemJob;
use Illuminate\Support\Facades\DB;

class PutawayService
{
    public function __construct(
        protected PutawayRepository $putawayRepository,
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
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

            $results[] = $this->putawayRepository->findById($assignment['putaway_id']);
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

        ProcessPutawayItemJob::dispatch($putawayId, $itemId, $data);
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

            return $this->putawayRepository->findById($id);
        });
    }
}
