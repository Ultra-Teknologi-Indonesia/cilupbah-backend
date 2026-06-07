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

    public function getById(string $id): ?StockAdjustment
    {
        return $this->adjustmentRepository->findById($id);
    }

    public function create(array $data): StockAdjustment
    {
        return DB::transaction(function () use ($data) {
            $adjustmentNo = $this->adjustmentRepository->generateAdjustmentNo();

            $adjustment = $this->adjustmentRepository->create([
                'adjustment_no' => $adjustmentNo,
                'transaction_date' => $data['transaction_date'],
                'location_id' => $data['location_id'],
                'status' => StockAdjustment::STATUS_DRAFT,
                'is_beginning_balance' => $data['is_beginning_balance'] ?? false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $inventory = $this->inventoryRepository->findExact(
                    $itemData['item_id'],
                    $data['location_id'],
                    $itemData['bin_id'] ?? null,
                    $itemData['batch_no'] ?? '',
                    $itemData['serial_no'] ?? ''
                );

                $systemQty = $inventory ? $inventory->on_hand : 0;
                $actualQty = $itemData['actual_qty'];

                $this->adjustmentRepository->createItem([
                    'stock_adjustment_id' => $adjustment->id,
                    'item_id' => $itemData['item_id'],
                    'bin_id' => $itemData['bin_id'] ?? null,
                    'batch_no' => $itemData['batch_no'] ?? null,
                    'serial_no' => $itemData['serial_no'] ?? null,
                    'system_qty' => $systemQty,
                    'actual_qty' => $actualQty,
                    'difference_qty' => $actualQty - $systemQty,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            return $this->adjustmentRepository->findById($adjustment->id);
        });
    }

    public function approve(string $id, string $approvedBy): StockAdjustment
    {
        $adjustment = $this->adjustmentRepository->findById($id);

        if (!$adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        if ($adjustment->status !== StockAdjustment::STATUS_DRAFT) {
            throw new \Exception("Hanya dokumen DRAFT yang bisa di-approve (status saat ini: {$adjustment->status}).");
        }

        $this->adjustmentRepository->updateStatus($id, StockAdjustment::STATUS_APPROVED, [
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        ProcessStockAdjustmentJob::dispatch($id, $approvedBy);

        return $this->adjustmentRepository->findById($id);
    }

    public function cancel(string $id): StockAdjustment
    {
        $adjustment = $this->adjustmentRepository->findById($id);

        if (!$adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        if ($adjustment->status !== StockAdjustment::STATUS_DRAFT) {
            throw new \Exception("Hanya dokumen DRAFT yang bisa di-cancel (status saat ini: {$adjustment->status}).");
        }

        $this->adjustmentRepository->updateStatus($id, StockAdjustment::STATUS_CANCELLED);

        return $this->adjustmentRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $adjustment = $this->adjustmentRepository->findById($id);

        if (!$adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        if ($adjustment->status !== StockAdjustment::STATUS_DRAFT) {
            throw new \Exception("Hanya dokumen DRAFT yang bisa dihapus (status saat ini: {$adjustment->status}).");
        }

        return $this->adjustmentRepository->delete($id);
    }
}
