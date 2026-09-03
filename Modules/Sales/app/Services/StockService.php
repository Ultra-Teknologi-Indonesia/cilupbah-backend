<?php

namespace Modules\Sales\Services;

use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Modules\Product\Repositories\ProductRepository;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Warehouse\Services\InboundBinPolicy;

class StockService
{
    use StockLockable;

    public function __construct(
        protected InventoryMovementRepository $movementRepository,
        protected InventoryRepository $inventoryRepository,
        protected ProductRepository $productRepository,
        protected InboundBinPolicy $inboundBinPolicy,
    ) {}

    private function cascadeBundle(string $itemId, int $qty, callable $operation): bool
    {
        $components = $this->productRepository->bundleComponentsForVariant($itemId);

        if ($components === null) {
            return false;
        }

        DB::transaction(function () use ($components, $qty, $operation) {
            foreach ($components as $component) {
                $operation(
                    $component['sku'] ?? "item:{$component['variant_id']}",
                    $component['variant_id'],
                    $qty * $component['qty'],
                );
            }
        });

        foreach ($components as $component) {
            SyncStockToChannelsJob::dispatch($component['variant_id'])->afterCommit();
        }

        return true;
    }

    public function reserve(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber, bool $enforce = true): void
    {
        $handled = $this->cascadeBundle($itemId, $qty, fn ($compSku, $compId, $compQty) => $this->reserveSingle($compSku, $compId, $locationId, $compQty, $transactionNumber, $enforce));

        if ($handled) {
            return;
        }

        $this->reserveSingle($sku, $itemId, $locationId, $qty, $transactionNumber, $enforce);
    }

    private function reserveSingle(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber, bool $enforce = true): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($sku, $itemId, $locationId, $qty, $transactionNumber, $enforce) {
            DB::transaction(function () use ($sku, $itemId, $locationId, $qty, $transactionNumber, $enforce) {

                $onHand = $this->inventoryRepository->sumOnHandAtLocation($itemId, $locationId);
                $onOrder = $this->inventoryRepository->sumOnOrderAtLocation($itemId, $locationId);
                $available = $onHand - $onOrder;

                if ($available < $qty) {
                    if ($enforce) {
                        throw new InsufficientStockException($sku, max(0, $available), $qty);
                    }

                    Log::warning('Stock oversold on channel booking', [
                        'sku' => $sku,
                        'item_id' => $itemId,
                        'location_id' => $locationId,
                        'available' => $available,
                        'requested' => $qty,
                        'transaction_number' => $transactionNumber,
                    ]);
                }

                $targetBinId = $this->inventoryRepository->findTargetBinForItemLocation($itemId, $locationId);
                $targetInv = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, $targetBinId);
                $targetInv->on_order = ((int) $targetInv->on_order) + $qty;
                $targetInv->recalculateAvailable();
                $this->inventoryRepository->updateStock($targetInv);

                $totalOnOrder = $this->inventoryRepository->sumOnOrderAtLocation($itemId, $locationId);
                $this->recordAllocation($itemId, $locationId, $qty, $transactionNumber, 'ORDER_RESERVE', $totalOnOrder, $targetBinId);
            });
        });
    }

    public function pick(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $handled = $this->cascadeBundle($itemId, $qty, fn ($compSku, $compId, $compQty) => $this->pickSingle($compSku, $compId, $locationId, $compQty, $transactionNumber));

        if ($handled) {
            return;
        }

        $this->pickSingle($sku, $itemId, $locationId, $qty, $transactionNumber);
    }

    private function pickSingle(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {

        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                $releasedBinId = null;
                $released = $this->releaseOutstandingReservation(
                    $itemId,
                    $locationId,
                    $qty,
                    $transactionNumber,
                    $releasedBinId,
                );
                if ($released > 0) {
                    $totalOnOrder = $this->inventoryRepository->sumOnOrderAtLocation($itemId, $locationId);
                    $this->recordAllocation($itemId, $locationId, -$released, $transactionNumber, 'ORDER_RELEASE', $totalOnOrder, $releasedBinId);
                }
            });
        });
    }

    public function restore(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $handled = $this->cascadeBundle($itemId, $qty, fn ($compSku, $compId, $compQty) => $this->restoreSingle($compSku, $compId, $locationId, $compQty, $transactionNumber));

        if ($handled) {
            return;
        }

        $this->restoreSingle($sku, $itemId, $locationId, $qty, $transactionNumber);
    }

    public function restoreToBin(string $sku, string $itemId, string $locationId, ?string $binId, int $qty, string $transactionNumber, string $source = 'ORDER_RESTORE_CANCEL', ?string $createdBy = null, ?string $referenceNumber = null): void
    {
        if ($binId !== null) {
            $this->inboundBinPolicy->assertConsumable($locationId, $binId, 'pengembalian stok');
        }

        if ($binId === null) {
            $this->restore($sku, $itemId, $locationId, $qty, $transactionNumber);

            return;
        }

        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $binId, $qty, $transactionNumber, $source, $createdBy, $referenceNumber) {
            DB::transaction(function () use ($itemId, $locationId, $binId, $qty, $transactionNumber, $source, $createdBy, $referenceNumber) {
                $binRow = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, $binId);
                $binRow->on_hand = ((int) $binRow->on_hand) + $qty;
                $this->inventoryRepository->updateStock($binRow);

                $this->movementRepository->create([
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'bin_id' => $binId,
                    'transaction_number' => $transactionNumber,
                    'source' => $source,
                    'reference_number' => $referenceNumber,
                    'qty' => $qty,
                    'balance' => $binRow->on_hand,
                    'transaction_date' => now(),
                    'created_by' => $createdBy ?: 'system',
                ]);
            });
        });
    }

    public function consumeFromBin(
        string $sku,
        string $itemId,
        string $locationId,
        string $binId,
        int $qty,
        string $transactionNumber,
        string $source,
        ?string $createdBy = null,
        ?\DateTimeInterface $transactionDate = null,
        ?string $referenceNumber = null,
    ): void {
        if ($qty <= 0) {
            return;
        }

        $this->inboundBinPolicy->assertConsumable($locationId, $binId, 'pemotongan stok');

        $this->withStockLock($itemId, $locationId, function () use ($sku, $itemId, $locationId, $binId, $qty, $transactionNumber, $source, $createdBy, $transactionDate, $referenceNumber) {
            DB::transaction(function () use ($sku, $itemId, $locationId, $binId, $qty, $transactionNumber, $source, $createdBy, $transactionDate, $referenceNumber) {
                $binRow = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, $binId);
                $onHand = (int) $binRow->on_hand;

                if ($onHand < $qty) {
                    throw new InsufficientStockException($sku, max(0, $onHand), $qty);
                }

                $binRow->on_hand = $onHand - $qty;
                $binRow->recalculateAvailable();
                $this->inventoryRepository->updateStock($binRow);

                $this->movementRepository->create([
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'bin_id' => $binId,
                    'transaction_number' => $transactionNumber,
                    'source' => $source,
                    'reference_number' => $referenceNumber,
                    'qty' => -$qty,
                    'balance' => $binRow->on_hand,
                    'transaction_date' => $transactionDate ?: now(),
                    'created_by' => $createdBy ?: 'system',
                ]);
            });
        });
    }

    private function restoreSingle(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {

                $aggregate = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);
                $aggregate->on_hand += $qty;
                $this->inventoryRepository->updateStock($aggregate);

                $this->movementRepository->create([
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'bin_id' => null,
                    'transaction_number' => $transactionNumber,
                    'source' => 'ORDER_RESTORE',
                    'qty' => $qty,
                    'balance' => $aggregate->on_hand,
                    'transaction_date' => now(),
                    'created_by' => 'system',
                ]);
            });
        });
    }

    public function cancel(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $handled = $this->cascadeBundle($itemId, $qty, fn ($compSku, $compId, $compQty) => $this->cancelSingle($compSku, $compId, $locationId, $compQty, $transactionNumber));

        if ($handled) {
            return;
        }

        $this->cancelSingle($sku, $itemId, $locationId, $qty, $transactionNumber);
    }

    private function releaseOutstandingReservation(
        string $itemId,
        string $locationId,
        int $requestedQty,
        string $transactionNumber,
        ?string &$releasedBinId = null,
    ): int {
        if ($requestedQty <= 0) {
            return 0;
        }

        $outstanding = $this->outstandingReservationQty(
            $itemId,
            $locationId,
            $transactionNumber,
        );

        if ($outstanding <= 0) {
            return 0;
        }

        $releaseQty = min($requestedQty, $outstanding);

        $releasedBinId = $this->reservationBinId(
            $itemId,
            $locationId,
            $transactionNumber,
        );

        $this->decrementOnOrderAtLocation(
            $itemId,
            $locationId,
            $releaseQty,
            $releasedBinId,
        );

        return $releaseQty;
    }

    private function outstandingReservationQty(
        string $itemId,
        string $locationId,
        string $transactionNumber,
    ): int {
        return max(0, (int) DB::table('inventory_movements')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('transaction_number', $transactionNumber)
            ->whereIn('source', InventoryMovementSourceMap::ORDER_LEDGER_SOURCES)
            ->sum('qty'));
    }

    private function reservationBinId(string $itemId, string $locationId, string $transactionNumber): ?string
    {
        $bins = DB::table('inventory_movements')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('transaction_number', $transactionNumber)
            ->where('source', 'ORDER_RESERVE')
            ->whereNotNull('bin_id')
            ->distinct()
            ->pluck('bin_id');

        return $bins->count() === 1 ? (string) $bins->first() : null;
    }

    private function decrementOnOrderAtLocation(string $itemId, string $locationId, int $qty, ?string $preferredBinId = null): int
    {
        if ($qty <= 0) {
            return 0;
        }

        $remaining = $qty;
        $query = Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('on_order', '>', 0);

        if ($preferredBinId !== null) {
            $query->orderByRaw('CASE WHEN bin_id = ? THEN 0 ELSE 1 END', [$preferredBinId]);
        }

        $rows = $query
            ->orderByRaw('bin_id IS NULL, on_order DESC')
            ->lockForUpdate()
            ->get();

        $totalReleased = 0;

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $row->on_order);
            if ($take <= 0) {
                continue;
            }

            $row->on_order = (int) $row->on_order - $take;
            $row->recalculateAvailable();
            $this->inventoryRepository->updateStock($row);

            $remaining -= $take;
            $totalReleased += $take;
        }

        return $totalReleased;
    }

    private function cancelSingle(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                $releasedBinId = null;
                $released = $this->releaseOutstandingReservation(
                    $itemId,
                    $locationId,
                    $qty,
                    $transactionNumber,
                    $releasedBinId,
                );
                if ($released > 0) {
                    $totalOnOrder = $this->inventoryRepository->sumOnOrderAtLocation($itemId, $locationId);
                    $this->recordAllocation($itemId, $locationId, -$released, $transactionNumber, 'ORDER_RELEASE', $totalOnOrder, $releasedBinId);
                }
            });
        });
    }

    public function releaseReservationByTransaction(string $transactionNumber): int
    {
        $targets = DB::table('inventory_movements')
            ->where('transaction_number', $transactionNumber)
            ->whereIn('source', InventoryMovementSourceMap::ORDER_LEDGER_SOURCES)
            ->select('item_id', 'location_id')
            ->groupBy('item_id', 'location_id')
            ->get();

        $released = 0;

        foreach ($targets as $row) {
            $this->withStockLock($row->item_id, $row->location_id, function () use ($row, $transactionNumber, &$released) {
                DB::transaction(function () use ($row, $transactionNumber, &$released) {
                    $outstanding = $this->outstandingReservationQty(
                        $row->item_id,
                        $row->location_id,
                        $transactionNumber,
                    );

                    if ($outstanding <= 0) {
                        return;
                    }

                    $actuallyReleased = $this->decrementOnOrderAtLocation(
                        $row->item_id,
                        $row->location_id,
                        $outstanding,
                    );
                    $totalOnOrder = $this->inventoryRepository->sumOnOrderAtLocation($row->item_id, $row->location_id);
                    $this->recordAllocation(
                        $row->item_id,
                        $row->location_id,
                        -$outstanding,
                        $transactionNumber,
                        'ORDER_RELEASE',
                        $totalOnOrder,
                        $this->reservationBinId($row->item_id, $row->location_id, $transactionNumber),
                    );

                    if ($actuallyReleased < $outstanding) {
                        Log::warning('Reservation ledger dilepas melebihi on_order aktual; drift ditutup tanpa saldo negatif', [
                            'item_id' => $row->item_id,
                            'location_id' => $row->location_id,
                            'transaction_number' => $transactionNumber,
                            'ledger_release' => $outstanding,
                            'inventory_release' => $actuallyReleased,
                        ]);
                    }

                    $released += $outstanding;
                });
            });
        }

        return $released;
    }

    public function reconcileTerminalReservationByTransaction(string $transactionNumber): int
    {
        $targets = DB::table('inventory_movements')
            ->where('transaction_number', $transactionNumber)
            ->whereIn('source', InventoryMovementSourceMap::ORDER_LEDGER_SOURCES)
            ->select('item_id', 'location_id')
            ->groupBy('item_id', 'location_id')
            ->get();

        $released = 0;

        foreach ($targets as $row) {
            $this->withStockLock($row->item_id, $row->location_id, function () use ($row, $transactionNumber, &$released) {
                DB::transaction(function () use ($row, $transactionNumber, &$released) {
                    $outstanding = $this->outstandingReservationQty(
                        $row->item_id,
                        $row->location_id,
                        $transactionNumber,
                    );

                    if ($outstanding <= 0) {
                        return;
                    }

                    $expectedActive = $this->activeReservationQtyAtLocation(
                        $row->item_id,
                        $row->location_id,
                    );
                    $currentOnOrder = $this->inventoryRepository->sumOnOrderAtLocation(
                        $row->item_id,
                        $row->location_id,
                    );

                    if ($currentOnOrder > $expectedActive) {
                        $this->decrementOnOrderAtLocation(
                            $row->item_id,
                            $row->location_id,
                            $currentOnOrder - $expectedActive,
                        );
                    } elseif ($currentOnOrder < $expectedActive) {
                        $this->incrementOnOrderAtLocation(
                            $row->item_id,
                            $row->location_id,
                            $expectedActive - $currentOnOrder,
                        );
                    }

                    $totalOnOrder = $this->inventoryRepository->sumOnOrderAtLocation(
                        $row->item_id,
                        $row->location_id,
                    );
                    $this->recordAllocation(
                        $row->item_id,
                        $row->location_id,
                        -$outstanding,
                        $transactionNumber,
                        'ORDER_RELEASE',
                        $totalOnOrder,
                        $this->reservationBinId($row->item_id, $row->location_id, $transactionNumber),
                    );

                    $released += $outstanding;
                });
            });
        }

        return $released;
    }

    private function activeReservationQtyAtLocation(string $itemId, string $locationId): int
    {
        $rows = DB::table('inventory_movements as im')
            ->leftJoin('sales_orders as so', 'so.salesorder_no', '=', 'im.transaction_number')
            ->where('im.item_id', $itemId)
            ->where('im.location_id', $locationId)
            ->whereIn('im.source', InventoryMovementSourceMap::ORDER_LEDGER_SOURCES)
            ->select('im.transaction_number', 'so.status', 'so.is_canceled')
            ->selectRaw('SUM(im.qty) as outstanding_qty')
            ->groupBy('im.transaction_number', 'so.status', 'so.is_canceled')
            ->havingRaw('SUM(im.qty) > 0')
            ->get();

        return (int) $rows
            ->reject(fn (object $row): bool => $this->isTerminalOrder($row))
            ->sum(fn (object $row): int => (int) $row->outstanding_qty);
    }

    private function isTerminalOrder(object $row): bool
    {
        return (bool) $row->is_canceled
            || in_array(strtolower((string) $row->status), [
                'cancelled', 'picked', 'packed', 'shipped', 'completed', 'delivered',
            ], true);
    }

    private function incrementOnOrderAtLocation(string $itemId, string $locationId, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $targetBinId = $this->inventoryRepository->findTargetBinForItemLocation($itemId, $locationId);
        $target = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, $targetBinId);
        $target->on_order = (int) $target->on_order + $qty;
        $target->recalculateAvailable();
        $this->inventoryRepository->updateStock($target);
    }

    public function recordExistingReservation(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): int
    {
        $components = $this->productRepository->bundleComponentsForVariant($itemId);

        if ($components !== null) {
            $created = 0;
            foreach ($components as $component) {
                $created += $this->recordExistingReservationSingle($component['variant_id'], $locationId, $qty * $component['qty'], $transactionNumber);
            }

            return $created;
        }

        return $this->recordExistingReservationSingle($itemId, $locationId, $qty, $transactionNumber);
    }

    private function recordExistingReservationSingle(string $itemId, string $locationId, int $qty, string $transactionNumber): int
    {
        if ($qty <= 0) {
            return 0;
        }

        if ($this->movementRepository->reservationLedgerExists($transactionNumber, $itemId, $locationId)) {
            return 0;
        }

        $onOrder = (int) $this->inventoryRepository->sumOnOrderAtLocation($itemId, $locationId);
        $targetBinId = $this->inventoryRepository->findTargetBinForItemLocation($itemId, $locationId);
        $this->recordAllocation($itemId, $locationId, $qty, $transactionNumber, 'ORDER_RESERVE', $onOrder, $targetBinId);

        return 1;
    }

    private function recordAllocation(string $itemId, string $locationId, int $qty, string $transactionNumber, string $source, int $reservedBalance, ?string $binId = null): void
    {
        $this->movementRepository->create([
            'item_id' => $itemId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'transaction_number' => $transactionNumber,
            'source' => $source,
            'qty' => $qty,
            'balance' => $reservedBalance,
            'transaction_date' => now(),
            'created_by' => 'system',
        ]);
    }
}
