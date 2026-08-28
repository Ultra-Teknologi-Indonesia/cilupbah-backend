<?php

declare(strict_types=1);

namespace Modules\Dashboard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Dashboard\Repositories\DashboardRepository;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

final class DashboardStockValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_value_uses_purchase_cost_and_never_negative_rack_value(): void
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Dashboard Cost Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Dashboard Product',
            'status' => 'master',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'DASHBOARD-COST-1',
            'is_active' => true,
        ]);
        $location = Location::factory()->create([
            'location_code' => 'WH-DASHBOARD-COST',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $negativeBin = LocationBin::factory()->create(['location_id' => $location->id, 'is_inbound' => false]);
        $positiveBin = LocationBin::factory()->create(['location_id' => $location->id, 'is_inbound' => false]);

        foreach ([[$negativeBin, -41, 76.25], [$positiveBin, 775, 0]] as [$bin, $qty, $cost]) {
            Inventory::create([
                'item_id' => $variant->id,
                'location_id' => $location->id,
                'bin_id' => $bin->id,
                'on_hand' => $qty,
                'on_order' => 0,
                'available' => $qty,
                'avg_cost' => $cost,
            ]);
        }
        InventoryMovement::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $positiveBin->id,
            'transaction_number' => 'PURCHASE-DASHBOARD-1',
            'source' => 'PURCHASE',
            'qty' => 2,
            'balance' => 2,
            'cost_per_unit' => 1000,
            'total_cost' => 2000,
            'transaction_date' => now(),
            'created_by' => 'test',
        ]);

        $value = app(DashboardRepository::class)->stockValue($location->id);

        $this->assertSame(734000.0, $value);
    }
}
