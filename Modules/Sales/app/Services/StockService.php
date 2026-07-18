<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Repositories\ProductRepository;
use Modules\Sales\Exceptions\InsufficientStockException;
use App\Traits\StockLockable;

class StockService
{
    use StockLockable;

    public function __construct(
        protected InventoryMovementRepository $movementRepository,
        protected InventoryRepository $inventoryRepository,
        protected ProductRepository $productRepository,
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
            SyncStockToChannelsJob::dispatch($component['variant_id']);
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

                $onHand   = $this->inventoryRepository->sumOnHandAtLocation($itemId, $locationId);
                $onOrder  = $this->inventoryRepository->sumOnOrderAtLocation($itemId, $locationId);
                $available = $onHand - $onOrder;

                if ($available < $qty) {
                    if ($enforce) {
                        throw new InsufficientStockException($sku, max(0, $available), $qty);
                    }

                    Log::warning('Stock oversold on channel booking', [
                        'sku'                => $sku,
                        'item_id'            => $itemId,
                        'location_id'        => $locationId,
                        'available'          => $available,
                        'requested'          => $qty,
                        'transaction_number' => $transactionNumber,
                    ]);
                }

                $aggregate = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);
                $aggregate->on_order = ((int) $aggregate->on_order) + $qty;
                $this->inventoryRepository->updateStock($aggregate);

                $this->recordAllocation($itemId, $locationId, $qty, $transactionNumber, 'ORDER_RESERVE', (int) $aggregate->on_order);
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
                $aggregate = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);
                $before = (int) $aggregate->on_order;
                $aggregate->on_order = max(0, $before - $qty);
                $this->inventoryRepository->updateStock($aggregate);

                $released = $before - (int) $aggregate->on_order;
                if ($released > 0) {
                    $this->recordAllocation($itemId, $locationId, -$released, $transactionNumber, 'ORDER_RELEASE', (int) $aggregate->on_order);
                }
            });
        });
    }

    public function ship(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $handled = $this->cascadeBundle($itemId, $qty, fn ($compSku, $compId, $compQty) => $this->shipSingle($compSku, $compId, $locationId, $compQty, $transactionNumber));

        if ($handled) {
            return;
        }

        $this->shipSingle($sku, $itemId, $locationId, $qty, $transactionNumber);
    }

    private function shipSingle(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {

                $balance = $this->inventoryRepository->sumOnHandAtLocation($itemId, $locationId);

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_SHIP',
                    'qty'                => 0,
                    'balance'            => $balance,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
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

    public function restoreToBin(string $sku, string $itemId, string $locationId, ?string $binId, int $qty, string $transactionNumber, string $source = 'ORDER_RESTORE_CANCEL'): void
    {
        if ($binId === null) {
            $this->restore($sku, $itemId, $locationId, $qty, $transactionNumber);
            return;
        }

        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $binId, $qty, $transactionNumber, $source) {
            DB::transaction(function () use ($itemId, $locationId, $binId, $qty, $transactionNumber, $source) {
                $binRow = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, $binId);
                $binRow->on_hand = ((int) $binRow->on_hand) + $qty;
                $this->inventoryRepository->updateStock($binRow);

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => $binId,
                    'transaction_number' => $transactionNumber,
                    'source'             => $source,
                    'qty'                => $qty,
                    'balance'            => $binRow->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
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
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_RESTORE',
                    'qty'                => $qty,
                    'balance'            => $aggregate->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
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

    private function cancelSingle(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {

                $aggregate = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);
                $before = (int) $aggregate->on_order;
                $aggregate->on_order = max(0, $before - $qty);
                $this->inventoryRepository->updateStock($aggregate);

                $released = $before - (int) $aggregate->on_order;
                if ($released > 0) {
                    $this->recordAllocation($itemId, $locationId, -$released, $transactionNumber, 'ORDER_RELEASE', (int) $aggregate->on_order);
                }
            });
        });
    }

    /**
     * Catat baris ledger alokasi pesanan (reserved) ke inventory_movements.
     * qty POSITIF saat reserve (on_order naik), NEGATIF saat release (pick/cancel).
     * `balance` = nilai on_order setelah perubahan. Baris ini dipisahkan dari
     * running-balance on_hand via partisi CASE di InventoryMovementRepository.
     */
    /**
     * BACKFILL: catat baris ledger ORDER_RESERVE untuk reservasi yang SUDAH ada
     * (on_order sudah terhitung) tanpa mengubah on_order lagi. Meng-expand bundle
     * ke komponen persis seperti reserve(). Return jumlah baris yang dibuat.
     * Idempotent per (transaction_number, item_id, location) via cek existing.
     */
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
        $this->recordAllocation($itemId, $locationId, $qty, $transactionNumber, 'ORDER_RESERVE', $onOrder);

        return 1;
    }

    private function recordAllocation(string $itemId, string $locationId, int $qty, string $transactionNumber, string $source, int $reservedBalance): void
    {
        $this->movementRepository->create([
            'item_id'            => $itemId,
            'location_id'        => $locationId,
            'bin_id'             => null,
            'transaction_number' => $transactionNumber,
            'source'             => $source,
            'qty'                => $qty,
            'balance'            => $reservedBalance,
            'transaction_date'   => now(),
            'created_by'         => 'system',
        ]);
    }
}
