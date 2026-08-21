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

    public function getQueryForExport(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
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

        (new ProcessStockAdjustmentJob($adjustment->id, $data['created_by']))->handle(
            $this->inventoryRepository,
            $this->movementRepository,
        );

        return $adjustment;
    }

    public function update(string $id, array $data): StockAdjustment
    {
        $adjustment = StockAdjustment::with('items')->find($id);

        if (!$adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        app(\Modules\Product\Services\BundleGuardService::class)->assertNotBundle(
            array_column($data['items'] ?? [], 'item_id'),
            'penyesuaian stok',
        );

        $affectedItemIds = [];

        DB::transaction(function () use ($adjustment, $data, &$affectedItemIds) {

            foreach ($adjustment->items as $item) {
                $affectedItemIds[] = $item->item_id;
                $delta = (float) $item->difference_qty;
                if ($delta === 0.0) {
                    continue;
                }

                $revertDelta = -1 * $delta;
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $adjustment->location_id,
                    $item->bin_id,
                );

                $inventory->on_hand += $revertDelta;
                $this->inventoryRepository->updateStock($inventory);
            }

            \Modules\Inventory\Models\InventoryMovement::where('transaction_number', $adjustment->adjustment_no)
                ->where('location_id', $adjustment->location_id)
                ->delete();

            \Modules\Inventory\Models\StockAdjustmentItem::where('stock_adjustment_id', $adjustment->id)->delete();

            $adjustment->update([
                'transaction_date' => $data['transaction_date'] ?? $adjustment->transaction_date,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $adjustment->notes,
                'is_beginning_balance' => $data['is_beginning_balance'] ?? $adjustment->is_beginning_balance,
            ]);

            foreach ($data['items'] as $itemData) {
                $affectedItemIds[] = $itemData['item_id'];
                $inventory = $this->inventoryRepository->findExact(
                    $itemData['item_id'],
                    $adjustment->location_id,
                    $itemData['bin_id'] ?? null,
                );

                $systemQty = $inventory ? (int) $inventory->on_hand : 0;
                $actualQty = (int) $itemData['actual_qty'];

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
        });

        $actor = $data['updated_by'] ?? $data['created_by'] ?? auth()->user()?->name ?? 'system';
        (new ProcessStockAdjustmentJob($adjustment->id, $actor))->handle(
            $this->inventoryRepository,
            $this->movementRepository,
        );

        foreach (array_values(array_unique($affectedItemIds)) as $itemId) {
            \Modules\Channel\Jobs\SyncStockToChannelsJob::dispatch($itemId);
        }

        return $this->adjustmentRepository->findById($adjustment->id);
    }

    public function delete(string $id): bool
    {
        $adjustment = StockAdjustment::withTrashed()->with('items')->find($id);

        if (!$adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        DB::transaction(function () use ($adjustment, $id) {
            $adjustedItemIds = [];

            foreach ($adjustment->items as $item) {
                $delta = (float) $item->difference_qty;
                if ($delta === 0.0) {
                    continue;
                }

                $revertDelta = -1 * $delta;
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $adjustment->location_id,
                    $item->bin_id,
                );

                $inventory->on_hand += $revertDelta;
                $this->inventoryRepository->updateStock($inventory);

                \Modules\Inventory\Models\InventoryMovement::where('transaction_number', $adjustment->adjustment_no)
                    ->where('item_id', $item->item_id)
                    ->where('location_id', $adjustment->location_id)
                    ->delete();

                $adjustedItemIds[] = $item->item_id;
            }

            $this->adjustmentRepository->delete($id);

            foreach (array_values(array_unique($adjustedItemIds)) as $itemId) {
                \Modules\Channel\Jobs\SyncStockToChannelsJob::dispatch($itemId);
            }
        });

        return true;
    }
}
