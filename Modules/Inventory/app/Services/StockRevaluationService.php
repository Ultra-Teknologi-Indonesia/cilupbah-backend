<?php

namespace Modules\Inventory\Services;

use Modules\Finance\Services\AutoJournalService;
use Modules\Inventory\Repositories\StockRevaluationRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Models\StockRevaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            }

            return $this->revaluationRepository->findById($revaluation->id);
        });
    }

    public function approve(string $id, array $data): StockRevaluation
    {
        $totalDelta = 0.0;
        $approvedRevaluation = DB::transaction(function () use ($id, $data, &$totalDelta) {
            $revaluation = $this->revaluationRepository->findByIdForUpdate($id);

            if (!$revaluation) {
                throw new \Exception('Dokumen penyesuaian nilai tidak ditemukan.');
            }

            if ($revaluation->status !== StockRevaluation::STATUS_DRAFT) {
                throw new \Exception('Hanya dokumen DRAFT yang bisa diapprove.');
            }

            foreach ($revaluation->items as $item) {
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $revaluation->location_id,
                    $item->bin_id,
                );

                $qty = (float) ($item->qty ?? $inventory->on_hand);
                $delta = ((float) $item->new_cost - (float) $item->old_cost) * $qty;
                $totalDelta += $delta;

                $inventory->avg_cost = $item->new_cost;
                $inventory->save();
            }

            $revaluation->update([
                'status'      => StockRevaluation::STATUS_APPROVED,
                'approved_by' => $data['approved_by'] ?? null,
                'approved_at' => now(),
            ]);

            return $this->revaluationRepository->findById($id);
        });

        // Jurnal revaluasi setelah transaksi sukses.
        try {
            app(AutoJournalService::class)->forStockRevaluation(
                $approvedRevaluation->revaluation_no,
                $approvedRevaluation->id,
                $approvedRevaluation->approved_at ?? now(),
                $totalDelta,
            );
        } catch (\Throwable $e) {
            Log::warning('AutoJournal stock revaluation gagal: ' . $e->getMessage(), [
                'revaluation_no' => $approvedRevaluation->revaluation_no,
            ]);
        }

        return $approvedRevaluation;
    }

    public function cancel(string $id): StockRevaluation
    {
        return DB::transaction(function () use ($id) {
            $revaluation = $this->revaluationRepository->findByIdForUpdate($id);

            if (!$revaluation) {
                throw new \Exception('Dokumen penyesuaian nilai tidak ditemukan.');
            }

            if ($revaluation->status === StockRevaluation::STATUS_APPROVED) {
                throw new \Exception('Dokumen yang sudah diapprove tidak bisa dibatalkan.');
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
