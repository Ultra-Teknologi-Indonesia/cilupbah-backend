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
                'status'         => StockRevaluation::STATUS_APPROVED,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $data['created_by'],
                'approved_by'    => $data['created_by'],
                'approved_at'    => now(),
            ]);

            foreach ($data['items'] as $itemData) {
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $itemData['item_id'],
                    $data['location_id'],
                    $itemData['bin_id'] ?? null,
                );

                $this->revaluationRepository->createItem([
                    'stock_revaluation_id' => $revaluation->id,
                    'item_id'              => $itemData['item_id'],
                    'bin_id'               => $itemData['bin_id'] ?? null,
                    'qty'                  => $inventory->on_hand,
                    'old_cost'             => $inventory->avg_cost,
                    'new_cost'             => $itemData['new_cost'],
                ]);

                $inventory->avg_cost = $itemData['new_cost'];
                $inventory->save();
            }

            return $this->revaluationRepository->findById($revaluation->id);
        });
    }

    public function cancel(string $id): StockRevaluation
    {
        return DB::transaction(function () use ($id) {
            $revaluation = $this->revaluationRepository->findByIdForUpdate($id);

            if (!$revaluation) {
                throw new \Exception('Dokumen penyesuaian nilai tidak ditemukan.');
            }

            if ($revaluation->status === StockRevaluation::STATUS_CANCELLED) {
                throw new \Exception('Dokumen sudah dibatalkan.');
            }

            foreach ($revaluation->items as $item) {
                $inventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $revaluation->location_id,
                    $item->bin_id,
                );

                if ($inventory) {
                    $inventory->avg_cost = $item->old_cost;
                    $inventory->save();
                }
            }

            $revaluation->update(['status' => StockRevaluation::STATUS_CANCELLED]);

            return $this->revaluationRepository->findById($id);
        });
    }
}
