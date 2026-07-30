<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\StockAdjustmentRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Jobs\ProcessStockAdjustmentJob;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    public function __construct(
        protected StockAdjustmentRepository $adjustmentRepository,
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->adjustmentRepository->getAllPaginated($limit);
    }

    public function getAllForExport(\Illuminate\Http\Request $request): array
    {
        return $this->adjustmentRepository->getAllForExport($request);
    }

    public function getQueryForExport(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $this->adjustmentRepository->getQueryForExport($request);
    }

    public function getById(string $id): ?StockAdjustment
    {
        return $this->adjustmentRepository->findById($id);
    }

    public function getItemsPaginated(string $id, int $limit = 10)
    {
        return $this->adjustmentRepository->getItemsPaginated($id, $limit);
    }

    public function getForPdf(string $id): ?StockAdjustment
    {
        return $this->adjustmentRepository->findForPdf($id);
    }

    public function getManyForPdf(array $ids)
    {
        return $this->adjustmentRepository->getManyForPdf($ids);
    }

    public function create(array $data): StockAdjustment
    {
        app(\Modules\Product\Services\BundleGuardService::class)->assertNotBundle(
            array_column($data['items'] ?? [], 'item_id'),
            'penyesuaian stok',
        );

        $adjustment = DB::transaction(function () use ($data) {
            $adjustmentNo = ! empty($data['adjustment_no'])
                ? $data['adjustment_no']
                : $this->adjustmentRepository->generateAdjustmentNo();

            $adjustment = $this->adjustmentRepository->create([
                'adjustment_no' => $adjustmentNo,
                'transaction_date' => $data['transaction_date'],
                'location_id' => $data['location_id'],
                'is_beginning_balance' => $data['is_beginning_balance'] ?? false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $inventory = $this->inventoryRepository->findExact(
                    $itemData['item_id'],
                    $data['location_id'],
                    $itemData['bin_id'] ?? null,
                );

                $systemQty = $inventory ? $inventory->on_hand : 0;
                $actualQty = $itemData['actual_qty'];

                $this->adjustmentRepository->createItem([
                    'stock_adjustment_id' => $adjustment->id,
                    'item_id' => $itemData['item_id'],
                    'bin_id' => $itemData['bin_id'] ?? null,
                    'system_qty' => $systemQty,
                    'actual_qty' => $actualQty,
                    'difference_qty' => $actualQty - $systemQty,
                    'unit_cost' => isset($itemData['unit_cost']) && $itemData['unit_cost'] !== ''
                        ? (float) $itemData['unit_cost']
                        : null,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            return $this->adjustmentRepository->findById($adjustment->id);
        });

        ProcessStockAdjustmentJob::dispatch($adjustment->id, $data['created_by'])->afterCommit();

        return $adjustment;
    }

    public function delete(string $id): bool
    {
        $adjustment = $this->adjustmentRepository->findById($id);

        if (!$adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        $this->adjustmentRepository->delete($id);

        return true;
    }
}
