<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class BinTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_bin_transfer_moves_stock_and_carries_cost(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-BT', 'location_name' => 'Gudang BT',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $binA = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-BT-A',
        ]);
        $binB = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-BT-B',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat BT', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-BT', 'sku' => 'P-BT', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 10, 'reserved' => 0, 'available' => 10, 'avg_cost' => 2500,
        ]);

        app(InventoryService::class)->binTransfer([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'source_bin_id' => $binA->id,
            'destination_bin_id' => $binB->id,
            'qty' => 4,
            'created_by' => 'tester',
        ]);

        $src = Inventory::where('bin_id', $binA->id)->first();
        $dst = Inventory::where('bin_id', $binB->id)->first();

        $this->assertSame(6, (int) $src->on_hand);
        $this->assertSame(4, (int) $dst->on_hand);
        $this->assertEquals(2500.0, (float) $dst->avg_cost);

        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binA->id, 'source' => 'BIN_TRANSFER_OUT', 'qty' => -4,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binB->id, 'source' => 'BIN_TRANSFER_IN', 'qty' => 4,
        ]);
    }

    public function test_bin_transfer_rejects_insufficient_stock(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-BT2', 'location_name' => 'Gudang BT2',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-BT2-A']);
        $binB = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-BT2-B']);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat BT2', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-BT2', 'sku' => 'P-BT2', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT2']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 2, 'reserved' => 0, 'available' => 2, 'avg_cost' => 100,
        ]);

        $this->expectException(\Exception::class);

        app(InventoryService::class)->binTransfer([
            'item_id' => $variant->id, 'location_id' => $location->id,
            'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id,
            'qty' => 5, 'created_by' => 'tester',
        ]);
    }
}
