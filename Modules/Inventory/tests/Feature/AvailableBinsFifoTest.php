<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class AvailableBinsFifoTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixture(): array
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-PUSAT', 'location_name' => 'Gudang Pusat',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binOld = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'OLD', 'bin_final_code' => 'WH-PUSAT-OLD',
        ]);
        $binNew = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'NEW', 'bin_final_code' => 'WH-PUSAT-NEW',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat FIFO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-FIFO', 'sku' => 'P-FIFO', 'is_active' => true,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-FIFO']);

        $invOld = Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binOld->id,
            'on_hand' => 5, 'on_order' => 0, 'available' => 5, 'avg_cost' => 500,
            'created_at' => now()->subDays(1),
        ]);
        $invNew = Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binNew->id,
            'on_hand' => 5, 'on_order' => 0, 'available' => 5, 'avg_cost' => 500,
            'created_at' => now(),
        ]);

        InventoryMovement::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binOld->id,
            'transaction_number' => 'M-OLD', 'source' => 'RECEIVE', 'qty' => 5, 'balance' => 5,
            'transaction_date' => now()->subDays(30), 'created_by' => 'tester',
        ]);
        InventoryMovement::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binNew->id,
            'transaction_number' => 'M-NEW', 'source' => 'RECEIVE', 'qty' => 5, 'balance' => 5,
            'transaction_date' => now()->subDays(2), 'created_by' => 'tester',
        ]);

        return compact('location', 'binOld', 'binNew', 'variant');
    }

    public function test_fifo_strategy_orders_available_bins_oldest_inbound_first(): void
    {
        $ctx = $this->seedFixture();

        $summary = app(InventoryService::class)
            ->buildSkuStockSummary($ctx['variant'], $ctx['location']->id, 'fifo');

        $codes = array_column($summary['available_bins'], 'code');

        $this->assertSame(['WH-PUSAT-OLD', 'WH-PUSAT-NEW'], $codes);
    }

    public function test_default_strategy_orders_by_created_at(): void
    {
        $ctx = $this->seedFixture();

        $summary = app(InventoryService::class)
            ->buildSkuStockSummary($ctx['variant'], $ctx['location']->id, 'default');

        $codes = array_column($summary['available_bins'], 'code');

        $this->assertSame(['WH-PUSAT-NEW', 'WH-PUSAT-OLD'], $codes);
    }
}
