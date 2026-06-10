<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\StockRevaluationRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Models\StockRevaluation;
use Illuminate\Support\Facades\DB;

class StockRevaluationService
{
    public function __construct(
        protected StockRevaluationRepository $revaluationRepository,
        protected InventoryRepository $inventoryRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->revaluationRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?StockRevaluation
    {
        return $this->revaluationRepository->findById($id);
    }

    public function create(array $data): StockRevaluation
    {
        return DB::transaction(function () use ($data) {
            $revaluationNo = $this->revaluationRepository->generateRevaluationNo();

            $revaluation = $this->revaluationRepository->create([
                'revaluation_no' => $revaluationNo,
                'location_id'    => $data['location_id'],
                'status'         => StockRevaluation::STATUS_DRAFT,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $inventory = $this->inventoryRepository->findExact(
                    $itemData['item_id'],
                    $data['location_id'],
                    $itemData['bin_id'] ?? null,
                );

                $this->revaluationRepository->createItem([
                    'stock_revaluation_id' => $revaluation->id,
                    'item_id'              => $itemData['item_id'],
                    'bin_id'               => $itemData['bin_id'] ?? null,
                    'qty'                  => $inventory ? $inventory->on_hand : 0,
                    'old_cost'             => $inventory ? $inventory->avg_cost : 0,
                    'new_cost'             => $itemData['new_cost'],
                ]);
            }

            return $this->revaluationRepository->findById($revaluation->id);
        });
    }

    public function approve(string $id, string $approvedBy): StockRevaluation
    {
        return DB::transaction(function () use ($id, $approvedBy) {
            $revaluation = $this->revaluationRepository->findByIdForUpdate($id);

            if (!$revaluation) {
                throw new \Exception('Dokumen revaluasi tidak ditemukan.');
            }

            if ($revaluation->status !== StockRevaluation::STATUS_DRAFT) {
                throw new \Exception("Hanya dokumen DRAFT yang bisa di-approve (status: {$revaluation->status}).");
            }

            foreach ($revaluation->items as $item) {
                $inventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $revaluation->location_id,
                    $item->bin_id,
                );

                if ($inventory) {
                    $inventory->avg_cost = $item->new_cost;
                    $inventory->save();
                }
            }

            $revaluation->update([
                'status'      => StockRevaluation::STATUS_APPROVED,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $this->revaluationRepository->findById($id);
        });
    }

    public function cancel(string $id): StockRevaluation
    {
        $revaluation = $this->revaluationRepository->findById($id);

        if (!$revaluation) {
            throw new \Exception('Dokumen revaluasi tidak ditemukan.');
        }

        if ($revaluation->status === StockRevaluation::STATUS_APPROVED) {
            throw new \Exception('Dokumen yang sudah di-approve tidak bisa di-cancel.');
        }

        $revaluation->update(['status' => StockRevaluation::STATUS_CANCELLED]);

        return $this->revaluationRepository->findById($id);
    }
}
