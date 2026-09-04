<?php

namespace Modules\Inventory\Services\RackImport;

use DomainException;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinMultiSkuRuleService;
use Modules\Warehouse\Services\BinOccupancyGuard;
use Modules\Warehouse\Services\SkuHomeBinGuard;

class RackAssignmentService
{
    public function __construct(
        private BinMultiSkuRuleService $ruleService,
    ) {}

    public function assign(string $locationId, string $binId, string $itemId, ?string $userId): void
    {
        $active = ProductVariant::query()
            ->whereKey($itemId)
            ->whereHas('product', fn ($query) => $query->whereNull('deleted_at'))
            ->exists();

        if (! $active) {
            throw new DomainException('SKU tidak aktif atau master produknya sudah dihapus.');
        }

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($locationId, $itemId, $binId);

        app(BinOccupancyGuard::class)->assertBinFitsSku($binId, $itemId);

        $this->assertBinNotPlannedForOther($locationId, $binId, $itemId);

        SkuRackAssignment::updateOrCreate(
            ['location_id' => $locationId, 'item_id' => $itemId],
            ['bin_id' => $binId, 'assigned_by' => $userId],
        );
    }

    private function assertBinNotPlannedForOther(string $locationId, string $binId, string $itemId): void
    {
        $location = Location::find($locationId);
        if (! $location || ! $location->enforcesStrictBinSku()) {
            return;
        }

        $bin = LocationBin::find($binId);
        if ($bin && $this->ruleService->allowsMultiSku($bin)) {
            return;
        }

        $takenByOther = SkuRackAssignment::query()
            ->join('product_variants as occupied_variants', 'occupied_variants.id', '=', 'sku_rack_assignments.item_id')
            ->join('products as occupied_products', 'occupied_products.id', '=', 'occupied_variants.product_id')
            ->where('sku_rack_assignments.bin_id', $binId)
            ->where('sku_rack_assignments.item_id', '!=', $itemId)
            ->whereNull('occupied_variants.deleted_at')
            ->whereNull('occupied_products.deleted_at')
            ->exists();

        if ($takenByOther) {
            throw new DomainException('Rak ini sudah dialokasikan untuk SKU lain (gudang kecil = 1 rak 1 SKU).');
        }
    }
}
