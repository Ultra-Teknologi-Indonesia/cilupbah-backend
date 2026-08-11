<?php

namespace Modules\Warehouse\Services;

use DomainException;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class SkuHomeBinGuard
{

    public const DEFAULT_BIN_CODE = 'DEFAULT';

    public function assertSkuFitsBin(string $locationId, string $itemId, string $targetBinId): void
    {
        $location = Location::find($locationId);
        if (! $location || ! $location->enforcesStrictBinSku()) {
            return;
        }

        if (! $this->isGuardedTargetBin($targetBinId)) {
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

        $homeCode = $homeBin?->bin_final_code ?? '-';
        $targetCode = LocationBin::find($targetBinId)?->bin_final_code ?? '-';

        throw new DomainException(sprintf(
            'SKU %s (%s) sudah menempati rak %s, jadi tidak bisa ditempatkan ke rak %s. Tempatkan ke rak %s.',
            $sku,
            $productName,
            $homeCode,
            $targetCode,
            $homeCode
        ));
    }

    public function isTargetBinAllowed(string $locationId, string $itemId, string $targetBinId): bool
    {
        $location = Location::find($locationId);
        if (! $location || ! $location->enforcesStrictBinSku()) {
            return true;
        }

        if (! $this->isGuardedTargetBin($targetBinId)) {
            return true;
        }

        return $this->firstConflictingInventory($locationId, $itemId, $targetBinId) === null;
    }

    protected function isGuardedTargetBin(string $targetBinId): bool
    {
        $bin = LocationBin::find($targetBinId);

        if (! $bin) {
            return false;
        }

        return ! $bin->is_inbound
            && (bool) $bin->is_stock_acknowledged
            && $bin->bin_final_code !== self::DEFAULT_BIN_CODE;
    }

    public function currentHomeBinId(string $locationId, string $itemId): ?string
    {
        $stockHome = Inventory::query()
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
            ->whereNotNull('bin_id')
            ->where(function ($w) {
                $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ->whereHas('bin', function ($q) {
                $q->where('is_inbound', false)
                    ->where('is_stock_acknowledged', true)

                    ->where('bin_final_code', '!=', self::DEFAULT_BIN_CODE);
            })
            ->value('bin_id');

        if ($stockHome !== null) {
            return $stockHome;
        }

        return $this->assignedBinId($locationId, $itemId);
    }

    public function assignedBinId(string $locationId, string $itemId): ?string
    {
        return SkuRackAssignment::query()
            ->where('location_id', $locationId)
            ->where('item_id', $itemId)
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
                $q->where('is_inbound', false)
                    ->where('is_stock_acknowledged', true)

                    ->where('bin_final_code', '!=', self::DEFAULT_BIN_CODE);
            })
            ->first();
    }
}
