<?php

namespace Modules\Inventory\Services;

use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Inventory\Jobs\ProcessStockAdjustmentJob;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\StockAdjustmentRepository;
use Modules\Inventory\Support\InventoryOnHandGuard;
use Modules\Inventory\Support\StockAdjustmentRule;
use Modules\Product\Services\BundleGuardService;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\InboundBinPolicy;

class StockAdjustmentService
{
    public function __construct(
        protected StockAdjustmentRepository $adjustmentRepository,
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
        protected StockAdjustmentRule $stockAdjustmentRule,
        protected InventoryOnHandGuard $onHandGuard,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->adjustmentRepository->getAllPaginated($limit);
    }

    public function getAllForExport(Request $request): array
    {
        return $this->adjustmentRepository->getAllForExport($request);
    }

    public function getQueryForExport(Request $request): Builder|\Illuminate\Database\Query\Builder
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

    public function assertAdjustmentsAccessible(array $ids): void
    {
        $this->adjustmentRepository->assertManyAccessible($ids);
    }

    public function create(array $data): StockAdjustment
    {
        WarehouseAccess::assert($data['location_id'] ?? null);
        $this->assertBinsBelongToLocation($data['items'] ?? [], $data['location_id']);

        app(BundleGuardService::class)->assertNotBundle(
            array_column($data['items'] ?? [], 'item_id'),
            'penyesuaian stok',
        );

        return DB::transaction(function () use ($data) {
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
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $itemData['item_id'],
                    $data['location_id'],
                    $itemData['bin_id'] ?? null,
                );

                $mode = $itemData['mode'] ?? StockAdjustmentRule::MODE_FINAL;
                $inputValue = array_key_exists('input_value', $itemData)
                    ? $itemData['input_value']
                    : $itemData['actual_qty'];
                $calculation = $this->stockAdjustmentRule->calculate(
                    systemQty: $inventory->on_hand,
                    inputValue: $inputValue,
                    mode: $mode,
                );

                $this->adjustmentRepository->createItem([
                    'stock_adjustment_id' => $adjustment->id,
                    'item_id' => $itemData['item_id'],
                    'bin_id' => $itemData['bin_id'] ?? null,
                    'system_qty' => $calculation->systemQty,
                    'actual_qty' => $calculation->actualQty,
                    'difference_qty' => $calculation->differenceQty,
                    'unit_cost' => isset($itemData['unit_cost']) && $itemData['unit_cost'] !== ''
                        ? (float) $itemData['unit_cost']
                        : null,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            (new ProcessStockAdjustmentJob($adjustment->id, $data['created_by']))->handle(
                $this->inventoryRepository,
                $this->movementRepository,
            );

            return $this->adjustmentRepository->findById($adjustment->id);
        });
    }

    public function update(string $id, array $data): StockAdjustment
    {
        $adjustmentQuery = StockAdjustment::with('items')->whereKey($id);
        WarehouseAccess::apply($adjustmentQuery, 'location_id');
        $adjustment = $adjustmentQuery->first();

        if (! $adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        $this->assertBinsBelongToLocation($data['items'] ?? [], $adjustment->location_id);

        app(BundleGuardService::class)->assertNotBundle(
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

                if (! empty($item->bin_id)) {
                    app(InboundBinPolicy::class)->assertConsumable(
                        $adjustment->location_id,
                        $item->bin_id,
                        'pembaruan penyesuaian stok',
                    );
                }

                $revertDelta = -1 * $delta;
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $adjustment->location_id,
                    $item->bin_id,
                );

                $inventory->on_hand = $this->onHandGuard->resultAfterDelta(
                    (int) $inventory->on_hand,
                    (int) $revertDelta,
                    'Pembaruan penyesuaian stok',
                );
                $this->inventoryRepository->updateStock($inventory);
            }

            InventoryMovement::where('transaction_number', $adjustment->adjustment_no)
                ->where('location_id', $adjustment->location_id)
                ->delete();

            StockAdjustmentItem::where('stock_adjustment_id', $adjustment->id)->delete();

            $adjustment->update([
                'transaction_date' => $data['transaction_date'] ?? $adjustment->transaction_date,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $adjustment->notes,
                'is_beginning_balance' => $data['is_beginning_balance'] ?? $adjustment->is_beginning_balance,
            ]);

            foreach ($data['items'] as $itemData) {
                $affectedItemIds[] = $itemData['item_id'];
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $itemData['item_id'],
                    $adjustment->location_id,
                    $itemData['bin_id'] ?? null,
                );

                $mode = $itemData['mode'] ?? StockAdjustmentRule::MODE_FINAL;
                $inputValue = array_key_exists('input_value', $itemData)
                    ? $itemData['input_value']
                    : $itemData['actual_qty'];
                $calculation = $this->stockAdjustmentRule->calculate(
                    systemQty: $inventory->on_hand,
                    inputValue: $inputValue,
                    mode: $mode,
                );

                $this->adjustmentRepository->createItem([
                    'stock_adjustment_id' => $adjustment->id,
                    'item_id' => $itemData['item_id'],
                    'bin_id' => $itemData['bin_id'] ?? null,
                    'system_qty' => $calculation->systemQty,
                    'actual_qty' => $calculation->actualQty,
                    'difference_qty' => $calculation->differenceQty,
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
            SyncStockToChannelsJob::dispatch($itemId);
        }

        return $this->adjustmentRepository->findById($adjustment->id);
    }

    public function patch(string $id, array $data): StockAdjustment
    {
        $deletedItemIds = [];

        DB::transaction(function () use ($id, $data, &$deletedItemIds) {
            $adjustmentQuery = StockAdjustment::lockForUpdate()->whereKey($id);
            WarehouseAccess::apply($adjustmentQuery, 'location_id');
            $adjustment = $adjustmentQuery->first();

            if (! $adjustment) {
                throw new \Exception('Dokumen adjustment tidak ditemukan.');
            }

            $changes = $data['changes'] ?? [];
            $creates = array_values($changes['create'] ?? []);
            $updates = array_values($changes['update'] ?? []);
            $deleteIds = array_values(array_unique($changes['delete_ids'] ?? []));
            $updateIds = array_column($updates, 'id');

            if (count($updateIds) !== count(array_unique($updateIds))) {
                throw new \InvalidArgumentException('Baris penyesuaian yang sama tidak boleh diperbarui lebih dari sekali.');
            }

            if (array_intersect($updateIds, $deleteIds) !== []) {
                throw new \InvalidArgumentException('Satu baris tidak boleh diperbarui dan dihapus dalam aksi yang sama.');
            }

            $requestedExistingIds = array_values(array_unique([...$updateIds, ...$deleteIds]));
            $items = StockAdjustmentItem::query()
                ->where('stock_adjustment_id', $adjustment->id)
                ->when(
                    $requestedExistingIds !== [],
                    fn ($query) => $query->whereIn('id', $requestedExistingIds),
                    fn ($query) => $query->whereRaw('1 = 0'),
                )
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if (count($requestedExistingIds) !== $items->count()) {
                throw new \InvalidArgumentException('Satu atau beberapa baris penyesuaian tidak ditemukan pada dokumen ini.');
            }

            $binValidationItems = [
                ...$creates,
                ...collect($updates)->map(function (array $update) use ($items): array {
                    $current = $items->get($update['id']);

                    return [
                        'item_id' => $current->item_id,
                        'bin_id' => array_key_exists('bin_id', $update)
                            ? $update['bin_id']
                            : $current->bin_id,
                    ];
                })->all(),
            ];

            $this->assertBinsBelongToLocation($binValidationItems, $adjustment->location_id);
            app(BundleGuardService::class)->assertNotBundle(
                array_column($binValidationItems, 'item_id'),
                'penyesuaian stok',
            );
            $this->assertPatchPairsAreUnique(
                $adjustment->id,
                $items,
                $creates,
                $updates,
                $deleteIds,
            );

            $adjustment->update([
                'transaction_date' => $data['transaction_date'] ?? $adjustment->transaction_date,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $adjustment->notes,
                'is_beginning_balance' => $data['is_beginning_balance'] ?? $adjustment->is_beginning_balance,
            ]);

            $processableItemIds = [];
            foreach ($deleteIds as $itemId) {
                $item = $items->get($itemId);
                $this->revertAppliedItem($adjustment, $item);
                $item->delete();
                $deletedItemIds[] = $item->item_id;
            }

            foreach ($updates as $updateData) {
                $item = $items->get($updateData['id']);
                if (! $this->patchChangesStock($item, $updateData)) {
                    $item->update([
                        'notes' => array_key_exists('notes', $updateData) ? $updateData['notes'] : $item->notes,
                        'unit_cost' => array_key_exists('unit_cost', $updateData) ? $updateData['unit_cost'] : $item->unit_cost,
                    ]);
                    continue;
                }

                $this->revertAppliedItem($adjustment, $item);
                $this->writeItemFromInput($adjustment, $item, $updateData);
                $processableItemIds[] = $item->id;
            }

            foreach ($creates as $createData) {
                $item = StockAdjustmentItem::make([
                    'stock_adjustment_id' => $adjustment->id,
                    'item_id' => $createData['item_id'],
                    'bin_id' => $createData['bin_id'] ?? null,

                    'system_qty' => 0,
                    'actual_qty' => 0,
                    'difference_qty' => 0,
                ]);
                $item->save();
                $this->writeItemFromInput($adjustment, $item, $createData);
                $processableItemIds[] = $item->id;
            }

            (new ProcessStockAdjustmentJob(
                $adjustment->id,
                $data['updated_by'] ?? auth()->user()?->name ?? 'system',
                $processableItemIds,
            ))->handle($this->inventoryRepository, $this->movementRepository);
        });

        foreach (array_values(array_unique($deletedItemIds)) as $itemId) {
            SyncStockToChannelsJob::dispatch($itemId);
        }

        return $this->adjustmentRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $adjustmentQuery = StockAdjustment::withTrashed()->with('items')->whereKey($id);
        WarehouseAccess::apply($adjustmentQuery, 'location_id');
        $adjustment = $adjustmentQuery->first();

        if (! $adjustment) {
            throw new \Exception('Dokumen adjustment tidak ditemukan.');
        }

        DB::transaction(function () use ($adjustment, $id) {
            $adjustedItemIds = [];

            foreach ($adjustment->items as $item) {
                $delta = (float) $item->difference_qty;
                if ($delta === 0.0) {
                    continue;
                }

                if (! empty($item->bin_id)) {
                    app(InboundBinPolicy::class)->assertConsumable(
                        $adjustment->location_id,
                        $item->bin_id,
                        'penghapusan penyesuaian stok',
                    );
                }

                $revertDelta = -1 * $delta;
                $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $adjustment->location_id,
                    $item->bin_id,
                );

                $inventory->on_hand = $this->onHandGuard->resultAfterDelta(
                    (int) $inventory->on_hand,
                    (int) $revertDelta,
                    'Penghapusan penyesuaian stok',
                );
                $this->inventoryRepository->updateStock($inventory);

                InventoryMovement::where('transaction_number', $adjustment->adjustment_no)
                    ->where('item_id', $item->item_id)
                    ->where('location_id', $adjustment->location_id)
                    ->delete();

                $adjustedItemIds[] = $item->item_id;
            }

            $this->adjustmentRepository->delete($id);

            foreach (array_values(array_unique($adjustedItemIds)) as $itemId) {
                SyncStockToChannelsJob::dispatch($itemId);
            }
        });

        return true;
    }

    private function assertBinsBelongToLocation(array $items, string $locationId): void
    {
        $itemBinKeys = collect($items)
            ->map(static fn (array $item): string => sprintf(
                '%s|%s',
                (string) ($item['item_id'] ?? ''),
                (string) ($item['bin_id'] ?? ''),
            ));

        if ($itemBinKeys->duplicates()->isNotEmpty()) {
            throw new \InvalidArgumentException(
                'SKU yang sama tidak boleh dicantumkan dua kali pada rak yang sama.',
            );
        }

        $binIds = collect($items)
            ->pluck('bin_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($binIds->isEmpty()) {
            return;
        }

        $validBinIds = LocationBin::query()
            ->where('location_id', $locationId)
            ->whereIn('id', $binIds->all())
            ->where('is_inbound', false)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id);

        if ($binIds->diff($validBinIds)->isNotEmpty()) {
            throw new \InvalidArgumentException(
                'Rak penyesuaian harus berada di gudang yang dipilih. Rak inbound/DEFAULT tidak dapat dipakai untuk penyesuaian; tempatkan penerimaan terlebih dahulu sebelum penyesuaian.',
            );
        }
    }

    private function assertPatchPairsAreUnique(
        string $adjustmentId,
        $affectedItems,
        array $creates,
        array $updates,
        array $deleteIds,
    ): void
    {
        $updatesById = collect($updates)->keyBy('id');
        $replacedOrDeletedIds = array_values(array_unique([
            ...array_column($updates, 'id'),
            ...$deleteIds,
        ]));
        $pairs = StockAdjustmentItem::query()
            ->where('stock_adjustment_id', $adjustmentId)
            ->when(
                $replacedOrDeletedIds !== [],
                fn ($query) => $query->whereNotIn('id', $replacedOrDeletedIds),
            )
            ->get(['item_id', 'bin_id'])
            ->map(fn (StockAdjustmentItem $item): string => sprintf('%s|%s', $item->item_id, $item->bin_id ?? ''))
            ->merge($affectedItems
                ->reject(fn (StockAdjustmentItem $item) => in_array($item->id, $deleteIds, true))
            ->map(function (StockAdjustmentItem $item) use ($updatesById): string {
                $update = $updatesById->get($item->id);

                $binId = $update !== null && array_key_exists('bin_id', $update)
                    ? $update['bin_id']
                    : $item->bin_id;

                return sprintf('%s|%s', $item->item_id, $binId ?? '');
            }))
            ->merge(collect($creates)->map(
                fn (array $item): string => sprintf('%s|%s', $item['item_id'], $item['bin_id'] ?? ''),
            ));

        if ($pairs->duplicates()->isNotEmpty()) {
            throw new \InvalidArgumentException('SKU yang sama tidak boleh dicantumkan dua kali pada rak yang sama.');
        }
    }

    private function patchChangesStock(StockAdjustmentItem $item, array $data): bool
    {
        $binChanged = array_key_exists('bin_id', $data) && $data['bin_id'] !== $item->bin_id;
        $mode = $data['mode'] ?? StockAdjustmentRule::MODE_FINAL;
        $input = array_key_exists('input_value', $data)
            ? (int) $data['input_value']
            : (int) $data['actual_qty'];

        return $binChanged
            || ($mode === StockAdjustmentRule::MODE_DELTA && $input !== (int) $item->difference_qty)
            || ($mode === StockAdjustmentRule::MODE_FINAL && $input !== (int) $item->actual_qty)
            || (array_key_exists('unit_cost', $data) && (float) $data['unit_cost'] !== (float) ($item->unit_cost ?? 0));
    }

    private function revertAppliedItem(StockAdjustment $adjustment, StockAdjustmentItem $item): void
    {
        $delta = (int) $item->difference_qty;
        if ($delta === 0) {
            return;
        }

        if ($item->bin_id) {
            app(InboundBinPolicy::class)->assertConsumable(
                $adjustment->location_id,
                $item->bin_id,
                'pembaruan penyesuaian stok',
            );
        }

        $inventory = $this->inventoryRepository->findOrCreateForUpdate(
            $item->item_id,
            $adjustment->location_id,
            $item->bin_id,
        );
        $inventory->on_hand = $this->onHandGuard->resultAfterDelta(
            (int) $inventory->on_hand,
            -$delta,
            'Pembaruan penyesuaian stok',
        );
        $this->inventoryRepository->updateStock($inventory);

        InventoryMovement::query()
            ->where('transaction_number', $adjustment->adjustment_no)
            ->where('item_id', $item->item_id)
            ->where('location_id', $adjustment->location_id)
            ->where('source', 'ADJUSTMENT')
            ->when(
                $item->bin_id === null,
                fn ($query) => $query->whereNull('bin_id'),
                fn ($query) => $query->where('bin_id', $item->bin_id),
            )
            ->delete();
    }

    private function writeItemFromInput(StockAdjustment $adjustment, StockAdjustmentItem $item, array $data): void
    {
        $binId = array_key_exists('bin_id', $data) ? $data['bin_id'] : $item->bin_id;
        $inventory = $this->inventoryRepository->findOrCreateForUpdate(
            $item->item_id,
            $adjustment->location_id,
            $binId,
        );
        $mode = $data['mode'] ?? StockAdjustmentRule::MODE_FINAL;
        $inputValue = array_key_exists('input_value', $data)
            ? $data['input_value']
            : $data['actual_qty'];
        $calculation = $this->stockAdjustmentRule->calculate(
            systemQty: $inventory->on_hand,
            inputValue: $inputValue,
            mode: $mode,
        );

        $item->update([
            'bin_id' => $binId,
            'system_qty' => $calculation->systemQty,
            'actual_qty' => $calculation->actualQty,
            'difference_qty' => $calculation->differenceQty,
            'unit_cost' => array_key_exists('unit_cost', $data)
                ? ($data['unit_cost'] !== '' ? (float) $data['unit_cost'] : null)
                : $item->unit_cost,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $item->notes,
        ]);
    }
}
