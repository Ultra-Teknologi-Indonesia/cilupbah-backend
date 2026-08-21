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

    public function test_two_step_flow_draft_print_receive(): void
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

        Inventory::create([
            'item_id' => $variant1->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 10, 'on_order' => 0, 'available' => 10, 'avg_cost' => 2500,
        ]);
        Inventory::create([
            'item_id' => $variant2->id, 'location_id' => $location->id, 'bin_id' => $binC->id,
            'on_hand' => 3, 'on_order' => 0, 'available' => 3, 'avg_cost' => 1000,
        ]);

        $svc = app(InventoryService::class);

        $transfer = $svc->createBinTransferDraft([
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant1->id, 'source_bin_id' => $binA->id, 'qty' => 4],
                ['item_id' => $variant2->id, 'source_bin_id' => $binC->id, 'qty' => 2],
            ],
        ]);

        $this->assertInstanceOf(BinTransfer::class, $transfer);
        $this->assertStringStartsWith('TRFO-', $transfer->transfer_number);
        $this->assertSame(BinTransfer::STATUS_BARU_DIBUAT, $transfer->status);
        $this->assertCount(2, $transfer->items);
        $this->assertSame(10, (int) Inventory::where('bin_id', $binA->id)->where('item_id', $variant1->id)->first()->on_hand);
        $this->assertSame(3, (int) Inventory::where('bin_id', $binC->id)->where('item_id', $variant2->id)->first()->on_hand);

        $transfer = $svc->printBinTransfer($transfer->id, 'tester');
        $this->assertSame(BinTransfer::STATUS_SEDANG_DIJALAN, $transfer->status);
        $this->assertNotNull($transfer->printed_at);
        $this->assertSame(6, (int) Inventory::where('bin_id', $binA->id)->where('item_id', $variant1->id)->first()->on_hand);
        $this->assertSame(1, (int) Inventory::where('bin_id', $binC->id)->where('item_id', $variant2->id)->first()->on_hand);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binA->id, 'source' => 'BIN_TRANSFER_OUT', 'qty' => -4,
            'transaction_number' => $transfer->transfer_number,
        ]);

        $this->assertNull(Inventory::where('bin_id', $binB->id)->where('item_id', $variant1->id)->first());

        $item1 = $transfer->items->firstWhere('item_id', $variant1->id);
        $item2 = $transfer->items->firstWhere('item_id', $variant2->id);

        $receipt = $svc->receiveBinTransfer($transfer->id, [
            'received_by' => 'tester',
            'items' => [
                ['bin_transfer_item_id' => $item1->id, 'destination_bin_id' => $binB->id, 'qty' => 4],
                ['bin_transfer_item_id' => $item2->id, 'destination_bin_id' => $binD->id, 'qty' => 2],
            ],
        ]);

        $this->assertStringStartsWith('TRFI-', $receipt->receipt_number);
        $this->assertSame(4, (int) Inventory::where('bin_id', $binB->id)->where('item_id', $variant1->id)->first()->on_hand);
        $this->assertSame(2, (int) Inventory::where('bin_id', $binD->id)->where('item_id', $variant2->id)->first()->on_hand);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binD->id, 'source' => 'BIN_TRANSFER_IN', 'qty' => 2,
            'transaction_number' => $receipt->receipt_number,
        ]);
        $this->assertSame(BinTransfer::STATUS_SELESAI, $transfer->fresh()->status);
    }

    public function test_partial_receipt_keeps_transfer_in_transit_and_rejects_over_receive(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-PR', 'location_name' => 'Gudang PR',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $src = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'S', 'bin_final_code' => 'WH-PR-S']);
        $dst = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'D', 'bin_final_code' => 'WH-PR-D']);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat PR', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-PR', 'sku' => 'P-PR', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-PR']);
        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $src->id,
            'on_hand' => 7, 'on_order' => 0, 'available' => 7, 'avg_cost' => 100,
        ]);

        $svc = app(InventoryService::class);
        $transfer = $svc->createBinTransferDraft([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $src->id, 'qty' => 7]],
        ]);
        $transfer = $svc->printBinTransfer($transfer->id, 'tester');
        $item = $transfer->items->first();

        $svc->receiveBinTransfer($transfer->id, [
            'received_by' => 'tester',
            'items' => [['bin_transfer_item_id' => $item->id, 'destination_bin_id' => $dst->id, 'qty' => 4]],
        ]);
        $this->assertSame(BinTransfer::STATUS_SEDANG_DIJALAN, $transfer->fresh()->status);
        $this->assertSame(4, (int) $item->fresh()->placed_qty);
        $this->assertSame(4, (int) Inventory::where('bin_id', $dst->id)->where('item_id', $variant->id)->first()->on_hand);

        try {
            $svc->receiveBinTransfer($transfer->id, [
                'received_by' => 'tester',
                'items' => [['bin_transfer_item_id' => $item->id, 'destination_bin_id' => $dst->id, 'qty' => 5]],
            ]);
            $this->fail('Seharusnya menolak qty terima melebihi sisa.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('melebihi jumlah yang tersedia', $e->getMessage());
        }

        $svc->receiveBinTransfer($transfer->id, [
            'received_by' => 'tester',
            'items' => [['bin_transfer_item_id' => $item->id, 'destination_bin_id' => $dst->id, 'qty' => 3]],
        ]);
        $this->assertSame(BinTransfer::STATUS_SELESAI, $transfer->fresh()->status);
        $this->assertSame(7, (int) Inventory::where('bin_id', $dst->id)->where('item_id', $variant->id)->first()->on_hand);
    }

    public function test_create_draft_rejects_insufficient_stock(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-BT2', 'location_name' => 'Gudang BT2',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-BT2-A']);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat BT2', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-BT2', 'sku' => 'P-BT2', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT2']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 2, 'on_order' => 0, 'available' => 2, 'avg_cost' => 100,
        ]);

        config(['inventory.allow_negative_stock' => false]);
        $this->expectException(\Exception::class);

        app(InventoryService::class)->createBinTransferDraft([
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'qty' => 5],
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
            'on_hand' => 5, 'on_order' => 0, 'available' => 5, 'avg_cost' => 100,
        ]);

        $user = $this->createPrivilegedUser();
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

        $user = $this->createPrivilegedUser();
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
            'on_hand' => 5, 'on_order' => 0, 'available' => 5, 'avg_cost' => 100,
        ]);
        Inventory::create([
            'item_id' => $atOtherWh->id, 'location_id' => $whB->id, 'bin_id' => $binB->id,
            'on_hand' => 5, 'on_order' => 0, 'available' => 5, 'avg_cost' => 100,
        ]);
        Inventory::create([
            'item_id' => $zeroStock->id, 'location_id' => $whA->id, 'bin_id' => $binA->id,
            'on_hand' => 0, 'on_order' => 0, 'available' => 0, 'avg_cost' => 100,
        ]);

        $user = $this->createPrivilegedUser();
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
        $user = $this->createPrivilegedUser();
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
            'on_hand' => 100, 'on_order' => 0, 'available' => 100, 'avg_cost' => 500,
        ]);

        $svc = app(InventoryService::class);

        $first = $svc->createBinTransferDraft([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'qty' => 1]],
        ]);
        $second = $svc->createBinTransferDraft([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'qty' => 1]],
        ]);

        $firstNo = (int) substr($first->transfer_number, 5);
        $secondNo = (int) substr($second->transfer_number, 5);
        $this->assertSame($firstNo + 1, $secondNo);
        $this->assertMatchesRegularExpression('/^TRFO-\d{9}$/', $first->transfer_number);
    }

    public function test_bin_transfer_index_filters_by_status(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-ST', 'location_name' => 'Gudang Status',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-ST-A']);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat ST', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-ST', 'sku' => 'P-ST', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-ST']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 100, 'on_order' => 0, 'available' => 100, 'avg_cost' => 500,
        ]);

        $svc = app(InventoryService::class);

        $draft = $svc->createBinTransferDraft([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'qty' => 1]],
        ]);

        $completed = $svc->createBinTransferDraft([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'qty' => 1]],
        ]);
        $completed->update(['status' => BinTransfer::STATUS_SELESAI]);

        $role = \App\Models\Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);

        $res = $this->actingAs($user)->getJson('/api/v1/inventory/bin-transfers?filter_status=BARU_DIBUAT');
        $res->assertOk();
        $items = $res->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($draft->id, $items[0]['id']);

        $res2 = $this->actingAs($user)->getJson('/api/v1/inventory/bin-transfers?status=SELESAI');
        $res2->assertOk();
        $items2 = $res2->json('data');
        $this->assertCount(1, $items2);
        $this->assertSame($completed->id, $items2[0]['id']);
    }
}
