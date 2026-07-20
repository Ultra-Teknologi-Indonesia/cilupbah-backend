<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Models\StockRevaluation;
use Modules\Inventory\Models\StockRevaluationItem;
use Modules\Inventory\Models\ReservedStock;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\Putaway;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use App\Models\User;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private Location $location2;
    private Product $product;
    private ProductVariant $variant;
    private ProductVariant $variant2;
    private Inventory $inventory;
    private LocationBin $binInbound;
    private LocationBin $binStorage;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        $this->actingAs($this->createPrivilegedUser(), 'sanctum');
        $this->seedBaseData();
    }

    private function seedBaseData(): void
    {
        $this->location = Location::create([
            'location_code' => 'WH-01',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $this->location2 = Location::create([
            'location_code' => 'WH-02',
            'location_name' => 'Gudang Cabang',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $this->binInbound = LocationBin::create([
            'location_id'    => $this->location->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R1',
            'column_code'    => 'C1',
            'bin_code'       => 'F1-R1-C1',
            'bin_final_code' => 'WH01-F1-R1-C1',
            'is_inbound'     => true,
        ]);

        $this->binStorage = LocationBin::create([
            'location_id'    => $this->location->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R2',
            'column_code'    => 'C1',
            'bin_code'       => 'F1-R2-C1',
            'bin_final_code' => 'WH01-F1-R2-C1',
            'is_inbound'     => false,
        ]);

        $category = \DB::table('categories')->insertGetId([
            'name'       => 'Electronics',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::create([
            'category_id' => $category,
            'name'        => 'Test Product',
            'sku'         => 'TST-001',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'TST-001-BLK',
            'sell_price' => 100000,
            'is_active'  => true,
        ]);

        $this->variant2 = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'TST-001-WHT',
            'sell_price' => 100000,
            'is_active'  => true,
        ]);

        $this->inventory = Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => null,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 100,
            'on_order'    => 0,
            'available'   => 100,
        ]);
    }

    public function test_list_stocks_returns_paginated(): void
    {
        $response = $this->getJson('/api/v1/inventory/stocks');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'message']);
    }

    public function test_show_stock_by_item(): void
    {
        $response = $this->getJson("/api/v1/inventory/stocks/{$this->variant->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Detail stok per item berhasil diambil');
    }

    public function test_movements_returns_paginated(): void
    {
        $response = $this->getJson('/api/v1/inventory/movements');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_stock_items_excludes_transit_location(): void
    {

        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 40,
            'on_order'    => 5,
            'available'   => 35,
        ]);

        $transit = Location::create([
            'location_code' => Location::SYSTEM_TRANSIT_CODE,
            'location_name' => 'Transit',
            'location_type' => 'Lokasi (Non Gudang)',
            'is_warehouse'  => false,
            'is_active'     => true,
        ]);
        $transitBin = LocationBin::create([
            'location_id'    => $transit->id,
            'bin_code'       => 'TRANSIT-DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound'     => true,
        ]);
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $transit->id,
            'bin_id'      => $transitBin->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 7,
            'on_order'    => 5,
            'available'   => 2,
        ]);

        $response = $this->getJson('/api/v1/inventory?filter[product_id]=' . $this->product->id);
        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('item_id', $this->variant->id);
        $this->assertNotNull($item, 'Varian tidak ditemukan di payload.');

        $locationIds = collect($item['location_stocks'])->pluck('location_id')->all();
        $this->assertContains($this->location->id, $locationIds);
        $this->assertNotContains($transit->id, $locationIds);

        $this->assertEquals(40, $item['total_stocks']['on_hand']);
        $this->assertEquals(5, $item['total_stocks']['on_order']);
        $this->assertEquals(35, $item['total_stocks']['available']);

        $metaLocationIds = collect($response->json('meta.locations'))->pluck('location_id')->all();
        $this->assertNotContains($transit->id, $metaLocationIds);
    }

    public function test_stock_products_groups_by_item(): void
    {
        $response = $this->getJson('/api/v1/inventory/stock-products');

        $response->assertOk();
    }

    public function test_items_to_stock_returns_product_variants(): void
    {
        $response = $this->getJson('/api/v1/inventory/items/to-stock');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_items_by_location(): void
    {
        $response = $this->getJson("/api/v1/inventory/items/by-location/{$this->location->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Stok per lokasi berhasil diambil.');
    }

    public function test_history_returns_paginated(): void
    {
        $response = $this->getJson('/api/v1/inventory/history');

        $response->assertOk();
    }

    public function test_adjust_stock_positive(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'qty'         => 50,
            'created_by'  => 'admin',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Stock adjustment berhasil.');

        $this->inventory->refresh();
        $this->assertEquals(150, $this->inventory->on_hand);
        $this->assertEquals(150, $this->inventory->available);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'ADJUSTMENT',
        ]);
    }

    public function test_adjust_stock_negative(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'qty'         => -30,
            'created_by'  => 'admin',
        ]);

        $response->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(70, $this->inventory->on_hand);
    }

    public function test_adjust_stock_prevents_negative_on_hand(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'qty'         => -200,
            'created_by'  => 'admin',
        ]);

        $response->assertStatus(422);

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
    }

    public function test_adjust_stock_creates_new_inventory_record(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'item_id'     => $this->variant2->id,
            'location_id' => $this->location->id,
            'qty'         => 25,
            'created_by'  => 'admin',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('inventories', [
            'item_id'     => $this->variant2->id,
            'location_id' => $this->location->id,
            'on_hand'     => 25,
            'available'   => 25,
        ]);
    }

    public function test_adjust_stock_validation_rejects_zero_qty(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'qty'         => 0,
            'created_by'  => 'admin',
        ]);

        $response->assertStatus(422);
    }

    public function test_adjust_stock_validation_requires_item_id(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $this->location->id,
            'qty'         => 10,
            'created_by'  => 'admin',
        ]);

        $response->assertStatus(422);
    }

    public function test_adjust_recalculates_available(): void
    {
        $this->inventory->update(['on_order' => 20, 'available' => 80]);

        $this->postJson('/api/v1/inventory/adjustments', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'qty'         => 10,
            'created_by'  => 'admin',
        ])->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(110, $this->inventory->on_hand);
        $this->assertEquals(20, $this->inventory->on_order);
        $this->assertEquals(90, $this->inventory->available);
    }

    public function test_transfer_out_deducts_source_stock(): void
    {
        $response = $this->postJson('/api/v1/inventory/transfers', [
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'created_by'              => 'admin',
            'items'                   => [
                [
                    'item_id' => $this->variant->id,
                    'qty'     => 30,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'IN_TRANSIT');

        $this->inventory->refresh();
        $this->assertEquals(70, $this->inventory->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'TRANSFER_OUT',
            'qty'     => -30,
        ]);
    }

    public function test_transfer_out_fails_insufficient_stock(): void
    {
        $response = $this->postJson('/api/v1/inventory/transfers', [
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'created_by'              => 'admin',
            'items'                   => [
                [
                    'item_id' => $this->variant->id,
                    'qty'     => 999,
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
    }

    public function test_transfer_out_requires_different_locations(): void
    {
        $response = $this->postJson('/api/v1/inventory/transfers', [
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location->id,
            'created_by'              => 'admin',
            'items'                   => [
                ['item_id' => $this->variant->id, 'qty' => 10],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_transfer_in_adds_stock_to_destination(): void
    {
        $outResponse = $this->postJson('/api/v1/inventory/transfers', [
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'created_by'              => 'admin',
            'items'                   => [
                ['item_id' => $this->variant->id, 'qty' => 20],
            ],
        ]);

        $transferId = $outResponse->json('data.id');

        $inResponse = $this->postJson("/api/v1/inventory/transfers/{$transferId}/receive", [
            'received_by' => 'staff-gudang',
        ]);

        $inResponse->assertOk()
            ->assertJsonPath('data.status', 'RECEIVED');

        $destInventory = Inventory::where('item_id', $this->variant->id)
            ->where('location_id', $this->location2->id)
            ->first();

        $this->assertNotNull($destInventory);
        $this->assertEquals(20, $destInventory->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->location2->id,
            'source'      => 'TRANSFER_IN',
            'qty'         => 20,
        ]);
    }

    public function test_transfer_in_fails_on_non_transit_status(): void
    {
        $transfer = InventoryTransfer::create([
            'transfer_number'         => 'TRF-TEST-001',
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'status'                  => InventoryTransfer::STATUS_RECEIVED,
            'created_by'              => 'admin',
        ]);

        $response = $this->postJson("/api/v1/inventory/transfers/{$transfer->id}/receive", [
            'received_by' => 'staff',
        ]);

        $response->assertStatus(422);
    }

    public function test_delete_transfer_only_draft(): void
    {
        $transfer = InventoryTransfer::create([
            'transfer_number'         => 'TRF-DEL-001',
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'status'                  => InventoryTransfer::STATUS_IN_TRANSIT,
            'created_by'              => 'admin',
        ]);

        $response = $this->deleteJson("/api/v1/inventory/transfers/{$transfer->id}");
        $response->assertStatus(422);
    }

    public function test_transfer_list_endpoints(): void
    {
        InventoryTransfer::create([
            'transfer_number'         => 'TRF-LIST-001',
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'status'                  => InventoryTransfer::STATUS_IN_TRANSIT,
            'created_by'              => 'admin',
        ]);

        $this->getJson('/api/v1/inventory/transfers')->assertOk();
        $this->getJson('/api/v1/inventory/transfers/transit')->assertOk();
        $this->getJson('/api/v1/inventory/transfers/out-finished')->assertOk();
    }

    public function test_transfer_show(): void
    {
        $transfer = InventoryTransfer::create([
            'transfer_number'         => 'TRF-SHOW-001',
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'status'                  => InventoryTransfer::STATUS_IN_TRANSIT,
            'created_by'              => 'admin',
        ]);

        $this->getJson("/api/v1/inventory/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.transfer_number', 'TRF-SHOW-001');
    }

    public function test_transfer_show_not_found(): void
    {
        $this->getJson('/api/v1/inventory/transfers/nonexistent-id')
            ->assertStatus(404);
    }

    public function test_mark_transfer_printed(): void
    {
        $transfer = InventoryTransfer::create([
            'transfer_number'         => 'TRF-PRINT-001',
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'status'                  => InventoryTransfer::STATUS_IN_TRANSIT,
            'created_by'              => 'admin',
        ]);

        $response = $this->postJson('/api/v1/inventory/transfer/mark-printed', [
            'transfer_id' => $transfer->id,
        ]);

        $response->assertOk();

        $transfer->refresh();
        $this->assertNotNull($transfer->printed_at);
        $this->assertNotNull($transfer->printed_by);
    }

    public function test_transfer_delivery_requires_transfer_id(): void
    {
        $this->getJson('/api/v1/inventory/transfer/delivery')
            ->assertStatus(422);
    }

    public function test_putaway_moves_stock_between_bins(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binInbound->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $response = $this->postJson('/api/v1/inventory/putaway', [
            'item_id'            => $this->variant->id,
            'location_id'        => $this->location->id,
            'source_bin_id'      => $this->binInbound->id,
            'destination_bin_id' => $this->binStorage->id,
            'qty'                => 30,
            'created_by'         => 'admin',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Putaway berhasil.');

        $sourceStock = Inventory::where('item_id', $this->variant->id)
            ->where('bin_id', $this->binInbound->id)
            ->first();
        $this->assertEquals(20, $sourceStock->on_hand);

        $destStock = Inventory::where('item_id', $this->variant->id)
            ->where('bin_id', $this->binStorage->id)
            ->first();
        $this->assertNotNull($destStock);
        $this->assertEquals(30, $destStock->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'PUTAWAY_OUT',
            'qty'     => -30,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'PUTAWAY_IN',
            'qty'     => 30,
        ]);
    }

    public function test_putaway_fails_insufficient_source_stock(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binInbound->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 5,
            'on_order'    => 0,
            'available'   => 5,
        ]);

        $response = $this->postJson('/api/v1/inventory/putaway', [
            'item_id'            => $this->variant->id,
            'location_id'        => $this->location->id,
            'source_bin_id'      => $this->binInbound->id,
            'destination_bin_id' => $this->binStorage->id,
            'qty'                => 50,
            'created_by'         => 'admin',
        ]);

        $response->assertStatus(422);
    }

    public function test_split_item_deducts_source_adds_target(): void
    {
        $response = $this->postJson('/api/v1/inventory/items/split-item', [
            'source_item_id' => $this->variant->id,
            'target_item_id' => $this->variant2->id,
            'location_id'    => $this->location->id,
            'qty_to_split'   => 10,
            'split_into_qty' => 100,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Item berhasil di-split.');

        $this->inventory->refresh();
        $this->assertEquals(90, $this->inventory->on_hand);

        $targetInventory = Inventory::where('item_id', $this->variant2->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertNotNull($targetInventory);
        $this->assertEquals(100, $targetInventory->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'SPLIT_OUT',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant2->id,
            'source'  => 'SPLIT_IN',
        ]);
    }

    public function test_split_item_fails_insufficient_stock(): void
    {
        $response = $this->postJson('/api/v1/inventory/items/split-item', [
            'source_item_id' => $this->variant->id,
            'target_item_id' => $this->variant2->id,
            'location_id'    => $this->location->id,
            'qty_to_split'   => 999,
            'split_into_qty' => 100,
        ]);

        $response->assertStatus(422);

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
    }

    public function test_split_item_requires_different_items(): void
    {
        $response = $this->postJson('/api/v1/inventory/items/split-item', [
            'source_item_id' => $this->variant->id,
            'target_item_id' => $this->variant->id,
            'location_id'    => $this->location->id,
            'qty_to_split'   => 1,
            'split_into_qty' => 10,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_stock_adjustment_document(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                [
                    'item_id'    => $this->variant->id,
                    'actual_qty' => 120,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'DRAFT');

        $this->assertDatabaseHas('stock_adjustments', [
            'location_id' => $this->location->id,
            'status'      => 'DRAFT',
        ]);
    }

    public function test_stock_adjustment_captures_system_qty(): void
    {
        $response = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                [
                    'item_id'    => $this->variant->id,
                    'actual_qty' => 80,
                ],
            ],
        ]);

        $adjustmentId = $response->json('data.id');
        $item = StockAdjustmentItem::where('stock_adjustment_id', $adjustmentId)->first();

        $this->assertEquals(100, $item->system_qty);
        $this->assertEquals(80, $item->actual_qty);
        $this->assertEquals(-20, $item->difference_qty);
    }

    public function test_approve_stock_adjustment(): void
    {
        $create = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 90],
            ],
        ]);

        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/inventory/adjustments/documents/{$id}/approve");

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertDatabaseHas('stock_adjustments', [
            'id'     => $id,
            'status' => 'APPROVED',
        ]);
    }

    public function test_approve_non_draft_adjustment_fails(): void
    {
        $create = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 90],
            ],
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/adjustments/documents/{$id}/approve")->assertStatus(202);
        $this->postJson("/api/v1/inventory/adjustments/documents/{$id}/approve")->assertStatus(422);
    }

    public function test_cancel_stock_adjustment(): void
    {
        $create = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 50],
            ],
        ]);

        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/inventory/adjustments/documents/{$id}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_cancel_approved_adjustment_fails(): void
    {
        $create = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 50],
            ],
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/adjustments/documents/{$id}/approve");
        $this->postJson("/api/v1/inventory/adjustments/documents/{$id}/cancel")->assertStatus(422);
    }

    public function test_delete_stock_adjustment_only_draft(): void
    {
        $create = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 50],
            ],
        ]);

        $id = $create->json('data.id');

        $this->deleteJson("/api/v1/inventory/adjustments/documents/{$id}")->assertOk();
        $this->assertSoftDeleted('stock_adjustments', ['id' => $id]);
    }

    public function test_delete_approved_adjustment_fails(): void
    {
        $create = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 50],
            ],
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/adjustments/documents/{$id}/approve");
        $this->deleteJson("/api/v1/inventory/adjustments/documents/{$id}")->assertStatus(422);
    }

    public function test_list_stock_adjustment_documents(): void
    {
        $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 50],
            ],
        ]);

        $this->getJson('/api/v1/inventory/adjustments/documents')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_show_stock_adjustment_document(): void
    {
        $create = $this->postJson('/api/v1/inventory/adjustments/documents', [
            'transaction_date' => now()->toDateTimeString(),
            'location_id'      => $this->location->id,
            'items'            => [
                ['item_id' => $this->variant->id, 'actual_qty' => 50],
            ],
        ]);

        $id = $create->json('data.id');

        $this->getJson("/api/v1/inventory/adjustments/documents/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    public function test_show_nonexistent_adjustment_returns_404(): void
    {
        $this->getJson('/api/v1/inventory/adjustments/documents/nonexistent-id')
            ->assertStatus(404);
    }

    public function test_create_reserved_stock(): void
    {
        $response = $this->postJson('/api/v1/inventory/reserved-stocks', [
            'location_id' => $this->location->id,
            'start_date'  => now()->toDateString(),
            'end_date'    => now()->addDays(7)->toDateString(),
            'items'       => [
                [
                    'item_id' => $this->variant->id,
                    'qty'     => 10,
                ],
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertDatabaseHas('reserved_stocks', [
            'location_id' => $this->location->id,
            'status'      => 'ACTIVE',
        ]);
    }

    public function test_cancel_reserved_stock(): void
    {
        $create = $this->postJson('/api/v1/inventory/reserved-stocks', [
            'location_id' => $this->location->id,
            'start_date'  => now()->toDateString(),
            'end_date'    => now()->addDays(7)->toDateString(),
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 10],
            ],
        ]);

        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/inventory/reserved-stocks/{$id}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_cancel_non_active_reserved_stock_fails(): void
    {
        $create = $this->postJson('/api/v1/inventory/reserved-stocks', [
            'location_id' => $this->location->id,
            'start_date'  => now()->toDateString(),
            'end_date'    => now()->addDays(7)->toDateString(),
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 10],
            ],
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/reserved-stocks/{$id}/cancel")->assertOk();
        $this->postJson("/api/v1/inventory/reserved-stocks/{$id}/cancel")->assertStatus(422);
    }

    public function test_list_reserved_stocks(): void
    {
        $this->getJson('/api/v1/inventory/reserved-stocks')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_show_reserved_stock(): void
    {
        $create = $this->postJson('/api/v1/inventory/reserved-stocks', [
            'location_id' => $this->location->id,
            'start_date'  => now()->toDateString(),
            'end_date'    => now()->addDays(7)->toDateString(),
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 10],
            ],
        ]);

        $id = $create->json('data.id');

        $this->getJson("/api/v1/inventory/reserved-stocks/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    public function test_create_stock_revaluation(): void
    {
        $response = $this->postJson('/api/v1/inventory/revaluations', [
            'location_id' => $this->location->id,
            'items'       => [
                [
                    'item_id'  => $this->variant->id,
                    'new_cost' => 150000,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_approve_revaluation_updates_avg_cost(): void
    {
        $this->inventory->update(['avg_cost' => 100000]);

        $create = $this->postJson('/api/v1/inventory/revaluations', [
            'location_id' => $this->location->id,
            'items'       => [
                [
                    'item_id'  => $this->variant->id,
                    'new_cost' => 150000,
                ],
            ],
        ]);

        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/inventory/revaluations/{$id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->inventory->refresh();
        $this->assertEquals('150000.00', $this->inventory->avg_cost);
    }

    public function test_approve_non_draft_revaluation_fails(): void
    {
        $create = $this->postJson('/api/v1/inventory/revaluations', [
            'location_id' => $this->location->id,
            'items'       => [
                ['item_id' => $this->variant->id, 'new_cost' => 150000],
            ],
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/revaluations/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/inventory/revaluations/{$id}/approve")->assertStatus(422);
    }

    public function test_cancel_revaluation(): void
    {
        $create = $this->postJson('/api/v1/inventory/revaluations', [
            'location_id' => $this->location->id,
            'items'       => [
                ['item_id' => $this->variant->id, 'new_cost' => 150000],
            ],
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/revaluations/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_cancel_approved_revaluation_fails(): void
    {
        $create = $this->postJson('/api/v1/inventory/revaluations', [
            'location_id' => $this->location->id,
            'items'       => [
                ['item_id' => $this->variant->id, 'new_cost' => 150000],
            ],
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/revaluations/{$id}/approve");
        $this->postJson("/api/v1/inventory/revaluations/{$id}/cancel")->assertStatus(422);
    }

    public function test_list_revaluations(): void
    {
        $this->getJson('/api/v1/inventory/revaluations')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_revaluation_captures_old_cost(): void
    {
        $this->inventory->update(['avg_cost' => 75000]);

        $create = $this->postJson('/api/v1/inventory/revaluations', [
            'location_id' => $this->location->id,
            'items'       => [
                ['item_id' => $this->variant->id, 'new_cost' => 90000],
            ],
        ]);

        $revalId = $create->json('data.id');
        $item = StockRevaluationItem::where('stock_revaluation_id', $revalId)->first();

        $this->assertEquals(75000, $item->old_cost);
        $this->assertEquals(90000, $item->new_cost);
    }

    public function test_create_stock_opname(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $response = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_start_stock_opname(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/inventory/stock-opname/{$id}/start");

        $response->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');
    }

    public function test_start_non_draft_opname_fails(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/stock-opname/{$id}/start")->assertOk();
        $this->postJson("/api/v1/inventory/stock-opname/{$id}/start")->assertStatus(422);
    }

    public function test_finalize_opname_fails_when_items_uncounted(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/stock-opname/{$id}/start");

        $response = $this->postJson("/api/v1/inventory/stock-opname/{$id}/finalize");
        $response->assertStatus(422);
    }

    public function test_count_opname_item_and_finalize(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $opnameId = $create->json('data.id');
        $opname = StockOpname::find($opnameId);
        $opnameItem = $opname->items->first();

        $this->postJson("/api/v1/inventory/stock-opname/{$opnameId}/start");

        $this->postJson("/api/v1/inventory/stock-opname/{$opnameId}/items/{$opnameItem->id}/count", [
            'qty_actual' => 48,
            'counted_by' => 'staff',
        ])->assertOk();

        $response = $this->postJson("/api/v1/inventory/stock-opname/{$opnameId}/finalize");
        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'FINALIZED');
    }

    public function test_cancel_stock_opname(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/stock-opname/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_cancel_finalized_opname_fails(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $opnameId = $create->json('data.id');
        $opnameItem = StockOpname::find($opnameId)->items->first();

        $this->postJson("/api/v1/inventory/stock-opname/{$opnameId}/start");
        $this->postJson("/api/v1/inventory/stock-opname/{$opnameId}/items/{$opnameItem->id}/count", [
            'qty_actual' => 50,
            'counted_by' => 'staff',
        ]);
        $this->postJson("/api/v1/inventory/stock-opname/{$opnameId}/finalize");

        $this->postJson("/api/v1/inventory/stock-opname/{$opnameId}/cancel")->assertStatus(422);
    }

    public function test_delete_stock_opname_only_draft(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $id = $create->json('data.id');

        $this->deleteJson("/api/v1/inventory/stock-opname/{$id}")->assertOk();
    }

    public function test_delete_in_progress_opname_fails(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/stock-opname/{$id}/start");
        $this->deleteJson("/api/v1/inventory/stock-opname/{$id}")->assertStatus(422);
    }

    public function test_mark_opname_printed(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => $this->binStorage->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => 50,
            'on_order'    => 0,
            'available'   => 50,
        ]);

        $create = $this->postJson('/api/v1/inventory/stock-opname', [
            'location_id' => $this->location->id,
        ]);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/inventory/stock-opname/{$id}/mark-printed")
            ->assertOk();

        $opname = StockOpname::find($id);
        $this->assertNotNull($opname->printed_at);
    }

    public function test_list_stock_opnames(): void
    {
        $this->getJson('/api/v1/inventory/stock-opname')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_stock_opname_bins_endpoint(): void
    {
        $this->getJson("/api/v1/inventory/stock-opname/bins?location_id={$this->location->id}")
            ->assertOk();
    }

    public function test_to_adjust_returns_stock_for_item_ids(): void
    {
        $response = $this->postJson('/api/v1/inventory/items/to-adjust', [
            'item_ids' => [$this->variant->id],
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_to_sell_by_location(): void
    {
        $response = $this->getJson("/api/v1/inventory/items/to-sell/{$this->location->id}");

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_batch_numbers_for_item(): void
    {
        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id'      => null,
            'batch_no'    => 'BATCH-001',
            'serial_no'   => '',
            'on_hand'     => 20,
            'on_order'    => 0,
            'available'   => 20,
        ]);

        $response = $this->getJson("/api/v1/inventory/items/{$this->variant->id}/batch-number");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_out_of_stock_in_order(): void
    {
        $this->getJson('/api/v1/inventory/out-of-stock-in-order')
            ->assertOk();
    }

    public function test_need_restock(): void
    {
        $this->getJson('/api/v1/inventory/need-restock')
            ->assertOk();
    }

    public function test_recalculate_available_formula(): void
    {
        $inv = new Inventory();
        $inv->on_hand = 100;
        $inv->on_order = 10;

        $inv->recalculateAvailable();

        $this->assertEquals(90, $inv->available);
    }

    public function test_recalculate_available_with_negative_result(): void
    {
        $inv = new Inventory();
        $inv->on_hand = 10;
        $inv->on_order = 20;

        $inv->recalculateAvailable();

        $this->assertEquals(-10, $inv->available);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/inventory/stocks')
            ->assertStatus(401);
    }
}
