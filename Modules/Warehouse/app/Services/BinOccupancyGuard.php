<?php

namespace Modules\Warehouse\Services;

use DomainException;
use Modules\Inventory\Models\Inventory;
use Modules\Warehouse\Models\LocationBin;

class BinOccupancyGuard
{
    public function assertBinFitsSku(string $binId, string $itemId): void
    {
        $bin = LocationBin::find($binId);

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
        $bin = LocationBin::find($binId);

        if (! $this->isGuardedBin($bin)) {
            return true;
        }

        return $this->firstConflictingInventory($binId, $itemId) === null;
    }

    public function currentOccupantItemId(string $binId): ?string
    {
        return Inventory::where('bin_id', $binId)
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

        return true;
    }

    protected function firstConflictingInventory(string $binId, string $itemId): ?Inventory
    {
        return Inventory::with('product.product')
            ->where('bin_id', $binId)
            ->where('item_id', '!=', $itemId)
            ->where(function ($w) {
                $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ->first();
    }
}
