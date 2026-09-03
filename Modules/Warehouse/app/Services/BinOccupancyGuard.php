<?php

namespace Modules\Warehouse\Services;

use App\Support\WarehouseAccess;
use DomainException;
use Modules\Inventory\Models\Inventory;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\Location;

class BinOccupancyGuard
{
    public function assertBinFitsSku(string $binId, string $itemId): void
    {
        $binQuery = LocationBin::whereKey($binId);
        WarehouseAccess::apply($binQuery, 'location_id');
        $bin = $binQuery->first();

        if (! $this->isGuardedBin($bin)) {
            return;
        }

        $occupant = $this->firstConflictingInventory($binId, $itemId);

        if ($occupant === null) {
            return;
        }

        $variant = $occupant->product;
        $sku = $variant?->sku ?? '';
        $productName = $variant?->product?->name ?? '';

        throw new DomainException(sprintf(
            'Rak %s sudah berisi SKU %s (%s). Satu rak hanya boleh berisi satu SKU.',
            $bin->bin_final_code,
            $sku,
            $productName
        ));
    }

    public function isBinFreeFor(string $binId, string $itemId): bool
    {
        $binQuery = LocationBin::whereKey($binId);
        WarehouseAccess::apply($binQuery, 'location_id');
        $bin = $binQuery->first();

        if (! $this->isGuardedBin($bin)) {
            return true;
        }

        return $this->firstConflictingInventory($binId, $itemId) === null;
    }

    public function currentOccupantItemId(string $binId): ?string
    {
        $query = Inventory::where('bin_id', $binId);
        WarehouseAccess::apply($query, 'location_id');

        return $query
            ->where(function ($w) {
                $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ->value('item_id');
    }

    protected function isGuardedBin(?LocationBin $bin): bool
    {
        if (! $bin) {
            return false;
        }

        if ($bin->is_inbound) {
            return false;
        }

        if (! $bin->is_stock_acknowledged) {
            return false;
        }

        if (app(BinMultiSkuRuleService::class)->allowsMultiSku($bin)) {
            return false;
        }

        $locationQuery = Location::whereKey($bin->location_id);
        WarehouseAccess::apply($locationQuery, 'id');
        $location = $locationQuery->first();

        return $location !== null && $location->enforcesStrictBinSku();
    }

    protected function firstConflictingInventory(string $binId, string $itemId): ?Inventory
    {
        $query = Inventory::with('product.product')
            ->where('bin_id', $binId)
            ->where('item_id', '!=', $itemId)
            ->where(function ($w) {
                $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ;
        WarehouseAccess::apply($query, 'location_id');

        return $query->first();
    }
}
