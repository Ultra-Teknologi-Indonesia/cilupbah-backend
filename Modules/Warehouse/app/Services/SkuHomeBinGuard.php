<?php

namespace Modules\Warehouse\Services;

use DomainException;
use Modules\Inventory\Models\Inventory;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class SkuHomeBinGuard
{
    public function assertSkuFitsBin(string $locationId, string $itemId, string $targetBinId): void
    {
        $location = Location::find($locationId);
        if (! $location || ! $location->enforcesStrictBinSku()) {
            return;
        }

        $conflict = $this->firstConflictingInventory($locationId, $itemId, $targetBinId);
        if ($conflict === null) {
            return;
        }

        $homeBin = $conflict->bin;
        $variant = $conflict->product;
        $sku = $variant?->sku ?? '';
        $productName = $variant?->product?->name ?? '';

        throw new DomainException(sprintf(
            'SKU %s (%s) sudah menempati rak %s di gudang ini. Pindahkan ke rak %s atau kosongkan rak lama dulu.',
            $sku,
            $productName,
            $homeBin?->bin_final_code ?? '-',
            $homeBin?->bin_final_code ?? '-'
        ));
    }

    public function isTargetBinAllowed(string $locationId, string $itemId, string $targetBinId): bool
    {
        $location = Location::find($locationId);
        if (! $location || ! $location->enforcesStrictBinSku()) {
            return true;
        }

        return $this->firstConflictingInventory($locationId, $itemId, $targetBinId) === null;
    }

    public function currentHomeBinId(string $locationId, string $itemId): ?string
    {
        return Inventory::query()
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->whereNotNull('bin_id')
            ->where(function ($w) {
                $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ->whereHas('bin', function ($q) {
                $q->where('is_inbound', false)->where('is_stock_acknowledged', true);
            })
            ->value('bin_id');
    }

    protected function firstConflictingInventory(string $locationId, string $itemId, string $targetBinId): ?Inventory
    {
        return Inventory::with(['bin', 'product.product'])
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->whereNotNull('bin_id')
            ->where('bin_id', '!=', $targetBinId)
            ->where(function ($w) {
                $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ->whereHas('bin', function ($q) {
                $q->where('is_inbound', false)->where('is_stock_acknowledged', true);
            })
            ->first();
    }
}
