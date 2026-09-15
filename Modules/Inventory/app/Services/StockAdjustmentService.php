<?php

namespace Modules\Inventory\Services;

use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Inventory\Exceptions\NegativeOnHandException;
use Modules\Inventory\Exceptions\NegativeStockAdjustmentException;
use Modules\Inventory\Exceptions\StockAdjustmentStockValidationException;
use Modules\Inventory\Jobs\ProcessStockAdjustmentJob;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\StockAdjustmentRepository;
use Modules\Inventory\Support\InventoryOnHandGuard;
use Modules\Inventory\Support\StockAdjustmentCalculation;
use Modules\Inventory\Support\StockAdjustmentRule;
use Modules\Product\Models\ProductVariant;
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
                $calculation = $this->calculateForInventory(
                    inventoryOnHand: (int) $inventory->on_hand,
                    inputValue: $inputValue,
                    mode: $mode,
                    itemId: $itemData['item_id'],
                    binId: $itemData['bin_id'] ?? null,
                    operation: 'Pembuatan penyesuaian stok',
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
                    $this->stockContext($item->item_id, $item->bin_id),
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
                $calculation = $this->calculateForInventory(
                    inventoryOnHand: (int) $inventory->on_hand,
                    inputValue: $inputValue,
                    mode: $mode,
                    itemId: $itemData['item_id'],
                    binId: $itemData['bin_id'] ?? null,
                    operation: 'Pembaruan penyesuaian stok',
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
            $this->assertPatchStockCanBeApplied(
                $adjustment,
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
                    $this->stockContext($item->item_id, $item->bin_id),
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
    ): void {
        $updatesById = collect($updates)->keyBy('id');
        $replacedOrDeletedIds = array_values(array_unique([
            ...array_column($updates, 'id'),
            ...$deleteIds,
        ]));
        $existingPairs = StockAdjustmentItem::query()
            ->where('stock_adjustment_id', $adjustmentId)
            ->when(
                $replacedOrDeletedIds !== [],
                fn ($query) => $query->whereNotIn('id', $replacedOrDeletedIds),
            )
            ->get(['item_id', 'bin_id'])
            ->toBase()
            ->map(fn (StockAdjustmentItem $item): string => sprintf('%s|%s', $item->item_id, $item->bin_id ?? ''));
        $affectedPairs = $affectedItems
            ->toBase()
            ->reject(fn (StockAdjustmentItem $item) => in_array($item->id, $deleteIds, true))
            ->map(function (StockAdjustmentItem $item) use ($updatesById): string {
                $update = $updatesById->get($item->id);

                $binId = $update !== null && array_key_exists('bin_id', $update)
                    ? $update['bin_id']
                    : $item->bin_id;

                return sprintf('%s|%s', $item->item_id, $binId ?? '');
            });
        $createdPairs = collect($creates)->map(
            fn (array $item): string => sprintf('%s|%s', $item['item_id'], $item['bin_id'] ?? ''),
        );
        $pairs = $existingPairs->merge($affectedPairs)->merge($createdPairs);

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
            : (int) ($data['actual_qty'] ?? ($mode === StockAdjustmentRule::MODE_DELTA
                ? $item->difference_qty
                : $item->actual_qty));

        return $binChanged
            || ($mode === StockAdjustmentRule::MODE_DELTA && $input !== (int) $item->difference_qty)
            || ($mode === StockAdjustmentRule::MODE_FINAL && $input !== (int) $item->actual_qty);
    }

    private function assertPatchStockCanBeApplied(
        StockAdjustment $adjustment,
        $items,
        array $creates,
        array $updates,
        array $deleteIds,
    ): void {
        $balances = [];
        $issues = [];
        $contexts = $this->patchStockContexts($items, $creates, $updates);

        $balance = function (string $itemId, ?string $binId) use (&$balances, $adjustment): int {
            $key = $this->inventoryKey($itemId, $binId);

            if (! array_key_exists($key, $balances)) {
                $balances[$key] = (int) $this->inventoryRepository
                    ->findOrCreateForUpdate($itemId, $adjustment->location_id, $binId)
                    ->on_hand;
            }

            return $balances[$key];
        };
        $applyDelta = function (string $itemId, ?string $binId, int $delta) use (&$balances, &$issues, $balance, $contexts): bool {
            $key = $this->inventoryKey($itemId, $binId);
            $current = $balance($itemId, $binId);
            $result = $current + $delta;

            if ($result < 0) {
                $context = $contexts[$key] ?? ['sku' => null, 'rack_code' => null];
                $issues[] = [
                    'sku' => $context['sku'],
                    'rack_code' => $context['rack_code'],
                    'current_on_hand' => $current,
                    'delta' => $delta,
                    'resulting_on_hand' => $result,
                    'reason' => 'Saldo on hand tidak boleh kurang dari 0.',
                ];

                return false;
            }

            $balances[$key] = $result;

            return true;
        };

        foreach ($deleteIds as $id) {
            $item = $items->get($id);
            $applyDelta($item->item_id, $item->bin_id, -(int) $item->difference_qty);
        }

        foreach ($updates as $data) {
            $item = $items->get($data['id']);
            if (! $this->patchChangesStock($item, $data)) {
                continue;
            }

            if (! $applyDelta($item->item_id, $item->bin_id, -(int) $item->difference_qty)) {
                continue;
            }

            $binId = array_key_exists('bin_id', $data) ? $data['bin_id'] : $item->bin_id;
            $this->simulatePatchInput($item->item_id, $binId, $data, $balance, $applyDelta, $issues, $contexts, $item);
        }

        foreach ($creates as $data) {
            $this->simulatePatchInput($data['item_id'], $data['bin_id'] ?? null, $data, $balance, $applyDelta, $issues, $contexts);
        }

        if ($issues !== []) {
            throw new StockAdjustmentStockValidationException($issues);
        }
    }

    private function simulatePatchInput(
        string $itemId,
        ?string $binId,
        array $data,
        callable $balance,
        callable $applyDelta,
        array &$issues,
        array $contexts,
        ?StockAdjustmentItem $existingItem = null,
    ): void {
        $mode = $data['mode'] ?? StockAdjustmentRule::MODE_FINAL;
        $input = array_key_exists('input_value', $data)
            ? $data['input_value']
            : ($data['actual_qty'] ?? ($mode === StockAdjustmentRule::MODE_DELTA
                ? $existingItem?->difference_qty
                : $existingItem?->actual_qty));
        $current = $balance($itemId, $binId);
        $normalizedMode = strtoupper(trim($mode));

        if ($normalizedMode === StockAdjustmentRule::MODE_DELTA) {
            $applyDelta($itemId, $binId, $this->stockAdjustmentRule->parseInteger($input, 'delta_qty'));

            return;
        }

        $finalQty = $this->stockAdjustmentRule->parseInteger($input, 'final_qty');
        if ($finalQty < 0) {
            $context = $contexts[$this->inventoryKey($itemId, $binId)] ?? ['sku' => null, 'rack_code' => null];
            $issues[] = [
                'sku' => $context['sku'],
                'rack_code' => $context['rack_code'],
                'current_on_hand' => $current,
                'delta' => $finalQty - $current,
                'resulting_on_hand' => $finalQty,
                'reason' => 'Nilai stok akhir tidak boleh kurang dari 0.',
            ];

            return;
        }

        $applyDelta($itemId, $binId, $finalQty - $current);
    }

    private function patchStockContexts($items, array $creates, array $updates): array
    {
        $pairs = [];
        $addPair = function (string $itemId, ?string $binId) use (&$pairs): void {
            $pairs[$this->inventoryKey($itemId, $binId)] = [
                'item_id' => $itemId,
                'bin_id' => $binId,
            ];
        };

        foreach ($items as $item) {
            $addPair($item->item_id, $item->bin_id);
        }
        foreach ($creates as $data) {
            $addPair($data['item_id'], $data['bin_id'] ?? null);
        }
        foreach ($updates as $data) {
            if (! array_key_exists('bin_id', $data)) {
                continue;
            }

            $addPair($items->get($data['id'])->item_id, $data['bin_id']);
        }

        $itemIds = collect($pairs)->pluck('item_id')->unique()->values();
        $binIds = collect($pairs)->pluck('bin_id')->filter()->unique()->values();
        $skus = ProductVariant::query()->whereIn('id', $itemIds)->pluck('sku', 'id');
        $rackCodes = LocationBin::query()->whereIn('id', $binIds)->pluck('bin_final_code', 'id');
        $contexts = [];

        foreach ($pairs as $key => $pair) {
            $contexts[$key] = [
                'sku' => $skus->get($pair['item_id']),
                'rack_code' => $pair['bin_id'] === null ? null : $rackCodes->get($pair['bin_id']),
            ];
        }

        return $contexts;
    }

    private function inventoryKey(string $itemId, ?string $binId): string
    {
        return $itemId.'|'.($binId ?? '');
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
            $this->stockContext($item->item_id, $item->bin_id),
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
            : ($data['actual_qty'] ?? ($mode === StockAdjustmentRule::MODE_DELTA
                ? $item->difference_qty
                : $item->actual_qty));
        $calculation = $this->calculateForInventory(
            inventoryOnHand: (int) $inventory->on_hand,
            inputValue: $inputValue,
            mode: $mode,
            itemId: $item->item_id,
            binId: $binId,
            operation: 'Pembaruan penyesuaian stok',
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

    private function calculateForInventory(
        int $inventoryOnHand,
        mixed $inputValue,
        string $mode,
        string $itemId,
        ?string $binId,
        string $operation,
    ): StockAdjustmentCalculation {
        try {
            return $this->stockAdjustmentRule->calculate(
                systemQty: $inventoryOnHand,
                inputValue: $inputValue,
                mode: $mode,
            );
        } catch (NegativeStockAdjustmentException $exception) {
            throw new NegativeOnHandException(
                $exception->systemQty,
                $exception->adjustmentQty,
                $operation,
                $this->stockContext($itemId, $binId),
                $exception,
            );
        }
    }

    private function stockContext(string $itemId, ?string $binId): array
    {
        return array_filter([
            'sku' => ProductVariant::query()->whereKey($itemId)->value('sku'),
            'rack_code' => $binId === null
                ? null
                : LocationBin::query()->whereKey($binId)->value('bin_final_code'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
