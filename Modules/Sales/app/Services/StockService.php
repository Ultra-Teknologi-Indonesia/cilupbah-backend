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
                // Hard-split (Jubelio-compatible):
                //   on_order = pesanan in-progress (operasional)
                //   reserved = cadangan manual promo (komersial)
                //   available = on_hand - on_order - reserved
                //
                // Order masuk → on_order naik. Reserved dipakai oleh
                // ReservedStockService (modul Inventory) untuk pencadangan
                // event flash sale, bukan oleh flow ini.
                $onHand   = $this->inventoryRepository->sumOnHandAtLocation($itemId, $locationId);
                $onOrder  = $this->inventoryRepository->sumOnOrderAtLocation($itemId, $locationId);
                $reserved = $this->inventoryRepository->sumReservedAtLocation($itemId, $locationId);
                $available = $onHand - $onOrder - $reserved;

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

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_BOOK',
                    'qty'                => -$qty,
                    'balance'            => $onHand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
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
                // Release booking dari aggregate (operasional → fulfilled).
                $aggregate = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);
                $aggregate->on_order = max(0, ((int) $aggregate->on_order) - $qty);
                $this->inventoryRepository->updateStock($aggregate);

                // Deduct physical on_hand across stock-bearing bins (FEFO).
                $remaining = $qty;
                foreach ($this->inventoryRepository->stockRowsForUpdate($itemId, $locationId) as $row) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($row->on_hand, $remaining);
                    $row->on_hand -= $take;
                    $this->inventoryRepository->updateStock($row);
                    $remaining -= $take;

                    $this->movementRepository->create([
                        'item_id'            => $itemId,
                        'location_id'        => $locationId,
                        'bin_id'             => $row->bin_id,
                        'transaction_number' => $transactionNumber,
                        'source'             => 'ORDER_PICK',
                        'qty'                => -$take,
                        'balance'            => $row->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => 'system',
                    ]);
                }

                // Not enough physical stock to cover the pick (oversold): absorb the
                // remainder on the aggregate row so totals stay consistent, and warn.
                if ($remaining > 0) {
                    $aggregate->on_hand -= $remaining;
                    $this->inventoryRepository->updateStock($aggregate);

                    Log::warning('Pick exceeds physical stock; absorbed on aggregate row', [
                        'item_id'            => $itemId,
                        'location_id'        => $locationId,
                        'shortfall'          => $remaining,
                        'transaction_number' => $transactionNumber,
                    ]);

                    $this->movementRepository->create([
                        'item_id'            => $itemId,
                        'location_id'        => $locationId,
                        'bin_id'             => null,
                        'transaction_number' => $transactionNumber,
                        'source'             => 'ORDER_PICK',
                        'qty'                => -$remaining,
                        'balance'            => $aggregate->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => 'system',
                    ]);
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
                // On_hand was already deducted at pick; shipping is audit-only.
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

    private function restoreSingle(string $sku, string $itemId, string $locationId, int $qty, string $transactionNumber): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty, $transactionNumber) {
            DB::transaction(function () use ($itemId, $locationId, $qty, $transactionNumber) {
                // Picked stock returning to inventory lands on the location-level
                // aggregate row (unassigned to a bin until a putaway reassigns it).
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
                // Release booking (order cancelled sebelum pick).
                $aggregate = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);
                $aggregate->on_order = max(0, ((int) $aggregate->on_order) - $qty);
                $this->inventoryRepository->updateStock($aggregate);

                $this->movementRepository->create([
                    'item_id'            => $itemId,
                    'location_id'        => $locationId,
                    'bin_id'             => null,
                    'transaction_number' => $transactionNumber,
                    'source'             => 'ORDER_CANCEL',
                    'qty'                => $qty,
                    'balance'            => $aggregate->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => 'system',
                ]);
            });
        });
    }
}
