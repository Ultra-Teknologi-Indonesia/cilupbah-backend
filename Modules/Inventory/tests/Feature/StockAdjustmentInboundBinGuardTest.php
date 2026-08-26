<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class StockAdjustmentInboundBinGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_adjustment_rejects_an_inbound_bin(): void
    {
        $location = Location::create([
            'location_code' => 'WH-INBOUND-GUARD',
            'location_name' => 'Gudang Inbound Guard',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $inbound = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Inbound Guard',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Product Inbound Guard',
            'sku' => 'P-INBOUND-GUARD',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'V-INBOUND-GUARD',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rak inbound/DEFAULT tidak dapat dipakai');

        app(StockAdjustmentService::class)->create([
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [[
                'item_id' => $variant->id,
                'bin_id' => $inbound->id,
                'mode' => 'DELTA',
                'input_value' => 1,
            ]],
        ]);
    }
}
