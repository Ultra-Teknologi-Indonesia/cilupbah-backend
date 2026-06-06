<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Order\Exceptions\InsufficientStockException;

class StockService
{
    public function __construct(
        protected InventoryMovementRepository $movementRepository,
    ) {}

    public function reserve(string $sku, string $itemId, int $locationId, int $qty, string $transactionNumber): void
    {
        $this->mutate($sku, $itemId, $locationId, function (Inventory $inventory) use ($sku, $qty, $transactionNumber, $itemId, $locationId) {
            if ($inventory->available < $qty) {
                throw new InsufficientStockException($sku, $inventory->available, $qty);
            }

            $inventory->available -= $qty;
            $inventory->reserved += $qty;
            $inventory->save();

            $this->movementRepository->create([
                'item_id'            => $itemId,
                'location_id'        => $locationId,
                'bin_id'             => null,
                'transaction_number' => $transactionNumber,
                'source'             => 'ORDER_RESERVE',
                'qty'                => -$qty,
                'balance'            => $inventory->available,
                'transaction_date'   => now(),
                'created_by'         => 'system',
            ]);
        });
    }

    public function pick(string $sku, string $itemId, int $locationId, int $qty, string $transactionNumber): void
    {
        $this->mutate($sku, $itemId, $locationId, function (Inventory $inventory) use ($qty, $transactionNumber, $itemId, $locationId) {
            $inventory->reserved -= $qty;
            $inventory->save();

            $this->movementRepository->create([
                'item_id'            => $itemId,
                'location_id'        => $locationId,
                'bin_id'             => null,
                'transaction_number' => $transactionNumber,
                'source'             => 'ORDER_PICK',
                'qty'                => -$qty,
                'balance'            => $inventory->available,
                'transaction_date'   => now(),
                'created_by'         => 'system',
            ]);
        });
    }

    public function ship(string $sku, string $itemId, int $locationId, int $qty, string $transactionNumber): void
    {
        $this->mutate($sku, $itemId, $locationId, function (Inventory $inventory) use ($qty, $transactionNumber, $itemId, $locationId) {
            $inventory->on_hand -= $qty;
            $inventory->save();

            $this->movementRepository->create([
                'item_id'            => $itemId,
                'location_id'        => $locationId,
                'bin_id'             => null,
                'transaction_number' => $transactionNumber,
                'source'             => 'ORDER_SHIP',
                'qty'                => -$qty,
                'balance'            => $inventory->on_hand,
                'transaction_date'   => now(),
                'created_by'         => 'system',
            ]);
        });
    }

    public function cancel(string $sku, string $itemId, int $locationId, int $qty, string $transactionNumber): void
    {
        $this->mutate($sku, $itemId, $locationId, function (Inventory $inventory) use ($qty, $transactionNumber, $itemId, $locationId) {
            $inventory->reserved -= $qty;
            $inventory->available += $qty;
            $inventory->save();

            $this->movementRepository->create([
                'item_id'            => $itemId,
                'location_id'        => $locationId,
                'bin_id'             => null,
                'transaction_number' => $transactionNumber,
                'source'             => 'ORDER_CANCEL',
                'qty'                => $qty,
                'balance'            => $inventory->available,
                'transaction_date'   => now(),
                'created_by'         => 'system',
            ]);
        });
    }

    /**
     * Acquire Redis lock → DB transaction → SELECT FOR UPDATE → execute callback → commit → release.
     *
     * @return bool false if lock could not be acquired
     */
    public function mutate(string $sku, string $itemId, int $locationId, callable $callback): bool
    {
        $lock = Cache::lock("stock:{$sku}", 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            DB::transaction(function () use ($itemId, $locationId, $callback) {
                $inventory = Inventory::where('item_id', $itemId)
                    ->where('location_id', $locationId)
                    ->whereNull('bin_id')
                    ->lockForUpdate()
                    ->firstOrFail();

                $callback($inventory);
            });

            return true;
        } finally {
            $lock->release();
        }
    }
}
