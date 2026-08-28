<?php

namespace Modules\Inventory\Services\RackImport;

use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\InventoryService;
use Modules\Warehouse\Services\BinOccupancyGuard;
use Modules\Warehouse\Services\SkuHomeBinGuard;

class RackPlacementService
{
    use StockLockable;

    public function __construct(
        private InventoryService $inventoryService,
    ) {}

    public function placeSkuToBin(string $locationId, string $binId, string $itemId, string $userId): int
    {
        app(SkuHomeBinGuard::class)->assertSkuFitsBin($locationId, $itemId, $binId);
        app(BinOccupancyGuard::class)->assertBinFitsSku($binId, $itemId);

        $placed = 0;

        $this->withStockLock($itemId, $locationId, function () use ($locationId, $binId, $itemId, $userId, &$placed) {
            DB::transaction(function () use ($locationId, $binId, $itemId, $userId, &$placed) {
                $transactionNumber = 'RACK-IMPORT-'.Str::upper(Str::random(12));
                $sources = Inventory::query()->pendingPlacement()
                    ->where('inventories.location_id', $locationId)
                    ->where('inventories.item_id', $itemId)
                    ->where('inventories.on_hand', '>', 0)
                    ->lockForUpdate()
                    ->get();

                foreach ($sources as $row) {
                    $qty = (int) $row->on_hand;
                    if ($qty <= 0) {
                        continue;
                    }
                    $this->inventoryService->putaway([
                        'item_id' => $itemId,
                        'location_id' => $locationId,
                        'source_bin_id' => $row->bin_id,
                        'destination_bin_id' => $binId,
                        'qty' => $qty,
                        'batch_no' => $row->batch_no ?? '',
                        'serial_no' => $row->serial_no ?? '',
                        'created_by' => "user:{$userId}",
                        'transaction_number' => $transactionNumber,
                        'source_out' => 'RACK_PLACEMENT_OUT',
                        'source_in' => 'RACK_PLACEMENT_IN',
                    ]);
                    $placed += $qty;
                }
            });
        });

        return $placed;
    }
}
