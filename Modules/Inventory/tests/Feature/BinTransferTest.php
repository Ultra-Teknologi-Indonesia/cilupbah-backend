<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\BinTransfer;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class BinTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_bin_transfer_moves_multi_item_across_different_bins(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-BT', 'location_name' => 'Gudang BT',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-BT-A']);
        $binB = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-BT-B']);
        $binC = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'C', 'bin_final_code' => 'WH-BT-C']);
        $binD = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'D', 'bin_final_code' => 'WH-BT-D']);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat BT', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-BT', 'sku' => 'P-BT', 'is_active' => true]);
        $variant1 = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT-1']);
        $variant2 = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT-2']);

        // V1 tersedia di rak A
        Inventory::create([
            'item_id' => $variant1->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 10, 'reserved' => 0, 'available' => 10, 'avg_cost' => 2500,
        ]);
        // V2 tersedia di rak C
        Inventory::create([
            'item_id' => $variant2->id, 'location_id' => $location->id, 'bin_id' => $binC->id,
            'on_hand' => 3, 'reserved' => 0, 'available' => 3, 'avg_cost' => 1000,
        ]);

        $transfer = app(InventoryService::class)->binTransfer([
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant1->id, 'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id, 'qty' => 4],
                ['item_id' => $variant2->id, 'source_bin_id' => $binC->id, 'destination_bin_id' => $binD->id, 'qty' => 2],
            ],
        ]);

        $this->assertInstanceOf(BinTransfer::class, $transfer);
        $this->assertStringStartsWith('TRFI-', $transfer->transfer_number);
        $this->assertCount(2, $transfer->items);

        $src1 = Inventory::where('bin_id', $binA->id)->where('item_id', $variant1->id)->first();
        $dst1 = Inventory::where('bin_id', $binB->id)->where('item_id', $variant1->id)->first();
        $src2 = Inventory::where('bin_id', $binC->id)->where('item_id', $variant2->id)->first();
        $dst2 = Inventory::where('bin_id', $binD->id)->where('item_id', $variant2->id)->first();

        $this->assertSame(6, (int) $src1->on_hand);
        $this->assertSame(4, (int) $dst1->on_hand);
        $this->assertSame(1, (int) $src2->on_hand);
        $this->assertSame(2, (int) $dst2->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binA->id, 'source' => 'BIN_TRANSFER_OUT', 'qty' => -4,
            'transaction_number' => $transfer->transfer_number,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binD->id, 'source' => 'BIN_TRANSFER_IN', 'qty' => 2,
            'transaction_number' => $transfer->transfer_number,
        ]);
        $this->assertDatabaseHas('bin_transfer_items', [
            'bin_transfer_id' => $transfer->id, 'item_id' => $variant1->id,
            'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id, 'qty' => 4,
        ]);
    }

    public function test_bin_transfer_rejects_when_source_equals_destination_per_item(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-EQ', 'location_name' => 'Gudang Eq',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-EQ-A']);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat Eq', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-EQ', 'sku' => 'P-EQ', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-EQ']);
        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 5, 'reserved' => 0, 'available' => 5, 'avg_cost' => 100,
        ]);

        $this->expectException(\Exception::class);

        app(InventoryService::class)->binTransfer([
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'destination_bin_id' => $binA->id, 'qty' => 1],
            ],
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
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id, 'qty' => 5],
            ],
        ]);
    }

    public function test_request_rejects_bin_from_different_location(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $wh1 = Location::create([
            'location_code' => 'WH-A', 'location_name' => 'Gudang A',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $wh2 = Location::create([
            'location_code' => 'WH-B', 'location_name' => 'Gudang B',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA1 = LocationBin::create(['location_id' => $wh1->id, 'bin_code' => 'A1', 'bin_final_code' => 'WH-A-1']);
        $binA2 = LocationBin::create(['location_id' => $wh1->id, 'bin_code' => 'A2', 'bin_final_code' => 'WH-A-2']);
        $binForeign = LocationBin::create(['location_id' => $wh2->id, 'bin_code' => 'B1', 'bin_final_code' => 'WH-B-1']);

        $catId = DB::table('categories')->insertGetId(['name' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $catId, 'name' => 'P', 'sku' => 'P', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V']);
        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $wh1->id, 'bin_id' => $binA1->id,
            'on_hand' => 5, 'reserved' => 0, 'available' => 5, 'avg_cost' => 100,
        ]);

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $res = $this->postJson('/api/v1/inventory/bin-transfers', [
            'location_id' => $wh1->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant->id, 'source_bin_id' => $binForeign->id, 'destination_bin_id' => $binA2->id, 'qty' => 1],
            ],
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.source_bin_id']);
        $this->assertStringContainsString(
            'Rak asal harus milik lokasi yang dipilih.',
            json_encode($res->json('errors')),
        );

        $res2 = $this->postJson('/api/v1/inventory/bin-transfers', [
            'location_id' => $wh1->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant->id, 'source_bin_id' => $binA1->id, 'destination_bin_id' => $binForeign->id, 'qty' => 1],
            ],
        ]);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.destination_bin_id']);
        $this->assertStringContainsString(
            'Rak tujuan harus milik lokasi yang dipilih.',
            json_encode($res2->json('errors')),
        );
    }

    public function test_by_sku_returns_404_when_require_stock_and_no_stock_at_location(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $wh = Location::create([
            'location_code' => 'WH-S', 'location_name' => 'Gudang S',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $catId = DB::table('categories')->insertGetId(['name' => 'CS', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $catId, 'name' => 'PS', 'sku' => 'PS', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-STOCKLESS']);

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $res = $this->getJson("/api/v1/inventory/stock/by-sku/V-STOCKLESS?location_id={$wh->id}&require_stock=1");
        $res->assertStatus(404);
        $res->assertJsonPath('message', 'SKU tidak punya stok di gudang ini.');

        $res2 = $this->getJson("/api/v1/inventory/stock/by-sku/V-STOCKLESS?location_id={$wh->id}");
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.available_bins', []);
    }

    public function test_stocked_items_returns_only_variants_with_stock_at_location(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $whA = Location::create([
            'location_code' => 'WH-STK-A', 'location_name' => 'Gudang STK A',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $whB = Location::create([
            'location_code' => 'WH-STK-B', 'location_name' => 'Gudang STK B',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $whA->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-STK-A-1']);
        $binB = LocationBin::create(['location_id' => $whB->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-STK-B-1']);

        $catId = DB::table('categories')->insertGetId(['name' => 'CSTK', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $catId, 'name' => 'PSTK', 'sku' => 'PSTK', 'is_active' => true]);
        $withStock = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-WITH-STOCK']);
        $atOtherWh = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-OTHER-WH']);
        $zeroStock = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-ZERO']);
        $noRow = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-NONE']);

        Inventory::create([
            'item_id' => $withStock->id, 'location_id' => $whA->id, 'bin_id' => $binA->id,
            'on_hand' => 5, 'reserved' => 0, 'available' => 5, 'avg_cost' => 100,
        ]);
        Inventory::create([
            'item_id' => $atOtherWh->id, 'location_id' => $whB->id, 'bin_id' => $binB->id,
            'on_hand' => 5, 'reserved' => 0, 'available' => 5, 'avg_cost' => 100,
        ]);
        Inventory::create([
            'item_id' => $zeroStock->id, 'location_id' => $whA->id, 'bin_id' => $binA->id,
            'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'avg_cost' => 100,
        ]);

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $res = $this->getJson("/api/v1/inventory/stock/items?location_id={$whA->id}");
        $res->assertStatus(200);
        $skus = collect($res->json('data'))->pluck('sku')->all();
        $this->assertContains('V-WITH-STOCK', $skus);
        $this->assertNotContains('V-OTHER-WH', $skus);
        $this->assertNotContains('V-ZERO', $skus);
        $this->assertNotContains('V-NONE', $skus);

        $searchRes = $this->getJson("/api/v1/inventory/stock/items?location_id={$whA->id}&search=WITH");
        $searchRes->assertStatus(200);
        $searchSkus = collect($searchRes->json('data'))->pluck('sku')->all();
        $this->assertSame(['V-WITH-STOCK'], $searchSkus);
    }

    public function test_stocked_items_requires_location_id(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $res = $this->getJson('/api/v1/inventory/stock/items');
        $res->assertStatus(422);
    }

    public function test_bin_transfer_number_is_sequential(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-SEQ', 'location_name' => 'Gudang Seq',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-SEQ-A']);
        $binB = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-SEQ-B']);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat Seq', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-Seq', 'sku' => 'P-Seq', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-Seq']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 100, 'reserved' => 0, 'available' => 100, 'avg_cost' => 500,
        ]);

        $svc = app(InventoryService::class);

        $first = $svc->binTransfer([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id, 'qty' => 1]],
        ]);
        $second = $svc->binTransfer([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id, 'qty' => 1]],
        ]);

        $firstNo = (int) substr($first->transfer_number, 5);
        $secondNo = (int) substr($second->transfer_number, 5);
        $this->assertSame($firstNo + 1, $secondNo);
        $this->assertMatchesRegularExpression('/^TRFI-\d{9}$/', $first->transfer_number);
    }
}
