<?php

namespace Modules\Inventory\Services\RackImport;

use DomainException;
use Modules\Inventory\Models\SkuRackAssignment;
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

        $takenByOther = SkuRackAssignment::where('bin_id', $binId)
            ->where('item_id', '!=', $itemId)
            ->exists();

        if ($takenByOther) {
            throw new DomainException('Rak ini sudah dialokasikan untuk SKU lain (gudang kecil = 1 rak 1 SKU).');
        }
    }
}
