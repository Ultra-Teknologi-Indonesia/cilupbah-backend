<?php

declare(strict_types=1);

namespace Modules\Report\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Repositories\ReportRepository;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

final class BarcodeReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_barcode_report_uses_small_warehouse_assignment_even_when_stock_is_zero(): void
    {
        $location = Location::query()
            ->where('is_small_warehouse', true)
            ->where('is_warehouse', true)
            ->where('is_active', true)
            ->first()
            ?? Location::factory()->smallWarehouse()->create([
                'location_code' => 'WH-BARCODE-SMALL',
            ]);
        $assignedBin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'bin_final_code' => 'O-A1-K1-X1',
            'is_stock_acknowledged' => true,
        ]);
        $otherBin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'bin_final_code' => 'O-A1-K1-X2',
            'is_stock_acknowledged' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Barcode Report Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Barcode Report Product',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_draft' => false,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'BARCODE-ASSIGNED-ZERO',
            'is_active' => true,
        ]);

        SkuRackAssignment::create([
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $assignedBin->id,
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $otherBin->id,
            'on_hand' => 9,
            'on_order' => 0,
            'available' => 9,
            'avg_cost' => 0,
        ]);

        $bins = app(ReportRepository::class)->barcodeKecilHomeBins([$variant->id]);

        $this->assertSame('O-A1-K1-X1', $bins[(string) $variant->id]);
    }
}
