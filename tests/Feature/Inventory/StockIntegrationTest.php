<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Supplier\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

class StockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private Location $location2;
    private Product $product;
    private ProductVariant $variant;
    private ProductVariant $variant2;
    private Inventory $inventory;
    private LocationBin $binInbound;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        $this->actingAs(User::factory()->create(), 'sanctum');
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
            'sell_price' => 120000,
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
            'reserved'    => 0,
            'available'   => 100,
        ]);

        $this->supplier = Supplier::create([
            'code'    => 'SUP-001',
            'name'    => 'PT Supplier Test',
            'status'  => 'active',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // §1 — SALES ORDER → STOCK (reserve, pick, cancel, restore)
    // ═══════════════════════════════════════════════════════════

    private function createSalesOrder(int $qty = 5, array $overrides = []): array
    {
        $payload = array_merge([
            'salesorder_no'      => 'SO-' . uniqid(),
            'customer_name'      => 'Toko Maju',
            'transaction_date'   => now()->toDateTimeString(),
            'sub_total'          => $qty * 100000,
            'total_disc'         => 0,
            'total_tax'          => 0,
            'shipping_cost'      => 10000,
            'insurance_cost'     => 0,
            'grand_total'        => ($qty * 100000) + 10000,
            'shipping_full_name' => 'Budi',
            'shipping_phone'     => '081234567890',
            'shipping_address'   => 'Jl. Test',
            'shipping_city'      => 'Jakarta',
            'shipping_province'  => 'DKI Jakarta',
            'shipping_post_code' => '12345',
            'shipping_country'   => 'Indonesia',
            'payment_method'     => 'bank_transfer',
            'source'             => 'manual',
            'items'              => [
                [
                    'sku'         => $this->variant->sku,
                    'description' => 'Test Product Black',
                    'qty_in_base' => $qty,
                    'price'       => 100000,
                    'amount'      => $qty * 100000,
                ],
            ],
        ], $overrides);

        $response = $this->postJson('/api/v1/sales', $payload);

        return ['response' => $response, 'payload' => $payload];
    }

    public function test_sales_order_reserves_stock(): void
    {
        $result = $this->createSalesOrder(10);
        $result['response']->assertStatus(201);

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
        $this->assertEquals(10, $this->inventory->reserved);
        $this->assertEquals(90, $this->inventory->available);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'ORDER_RESERVE',
        ]);
    }

    public function test_sales_order_cancel_releases_reserved_stock(): void
    {
        $result = $this->createSalesOrder(15);
        $orderId = $result['response']->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(15, $this->inventory->reserved);

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'cancelled'])
            ->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->reserved);
        $this->assertEquals(100, $this->inventory->on_hand);
        $this->assertEquals(100, $this->inventory->available);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'ORDER_CANCEL',
        ]);
    }

    public function test_sales_order_pick_deducts_on_hand_and_reserved(): void
    {
        $result = $this->createSalesOrder(8);
        $orderId = $result['response']->json('data.id');

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked'])
            ->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(92, $this->inventory->on_hand);
        $this->assertEquals(0, $this->inventory->reserved);
        $this->assertEquals(92, $this->inventory->available);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'ORDER_PICK',
        ]);
    }

    public function test_sales_order_ship_records_movement_only(): void
    {
        $result = $this->createSalesOrder(5);
        $orderId = $result['response']->json('data.id');

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked']);
        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'packed']);

        $this->inventory->refresh();
        $onHandBeforeShip = $this->inventory->on_hand;

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'shipped'])
            ->assertOk();

        $this->inventory->refresh();
        $this->assertEquals($onHandBeforeShip, $this->inventory->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'ORDER_SHIP',
        ]);
    }

    public function test_sales_cancel_after_pick_restores_on_hand(): void
    {
        $result = $this->createSalesOrder(10);
        $orderId = $result['response']->json('data.id');

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked']);

        $this->inventory->refresh();
        $this->assertEquals(90, $this->inventory->on_hand);

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'cancelled'])
            ->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
        $this->assertEquals(0, $this->inventory->reserved);
        $this->assertEquals(100, $this->inventory->available);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'ORDER_RESTORE',
        ]);
    }

    public function test_multiple_orders_accumulate_reserved(): void
    {
        $this->createSalesOrder(10);
        $this->createSalesOrder(20);
        $this->createSalesOrder(5);

        $this->inventory->refresh();
        $this->assertEquals(35, $this->inventory->reserved);
        $this->assertEquals(100, $this->inventory->on_hand);
        $this->assertEquals(65, $this->inventory->available);
    }

    public function test_sales_full_lifecycle_stock_consistency(): void
    {
        $result = $this->createSalesOrder(10);
        $orderId = $result['response']->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
        $this->assertEquals(10, $this->inventory->reserved);
        $this->assertEquals(90, $this->inventory->available);

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked']);
        $this->inventory->refresh();
        $this->assertEquals(90, $this->inventory->on_hand);
        $this->assertEquals(0, $this->inventory->reserved);
        $this->assertEquals(90, $this->inventory->available);

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'packed']);
        $this->inventory->refresh();
        $this->assertEquals(90, $this->inventory->on_hand);

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'shipped']);
        $this->inventory->refresh();
        $this->assertEquals(90, $this->inventory->on_hand);
        $this->assertEquals(0, $this->inventory->reserved);
        $this->assertEquals(90, $this->inventory->available);
    }

    // ═══════════════════════════════════════════════════════════
    // §2 — PURCHASE ORDER → STOCK (on_order + inbound receive)
    // ═══════════════════════════════════════════════════════════

    public function test_po_approve_increases_on_order(): void
    {
        $createResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 50, 'unit_price' => 80000],
            ],
        ]);
        $createResponse->assertStatus(201);
        $poId = $createResponse->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->on_order);

        $this->postJson("/api/v1/purchase/orders/{$poId}/approve")->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(50, $this->inventory->on_order);
        $this->assertEquals(50, $this->inventory->available);
    }

    public function test_po_cancel_releases_on_order(): void
    {
        $createResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 30, 'unit_price' => 80000],
            ],
        ]);
        $poId = $createResponse->json('data.id');

        $this->postJson("/api/v1/purchase/orders/{$poId}/approve");

        $this->inventory->refresh();
        $this->assertEquals(30, $this->inventory->on_order);

        $this->postJson("/api/v1/purchase/orders/{$poId}/cancel")->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->on_order);
        $this->assertEquals(100, $this->inventory->available);
    }

    public function test_po_receive_decreases_on_order_and_creates_inbound(): void
    {
        $createResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 20, 'unit_price' => 80000],
            ],
        ]);
        $poId = $createResponse->json('data.id');

        $this->postJson("/api/v1/purchase/orders/{$poId}/approve");

        $this->inventory->refresh();
        $this->assertEquals(20, $this->inventory->on_order);

        $po = \Modules\Purchase\Models\PurchaseOrder::find($poId);
        $poItem = $po->items->first();

        $receiveResponse = $this->postJson("/api/v1/purchase/orders/{$poId}/receive", [
            'received_by' => 'staff-gudang',
            'items'       => [
                ['purchase_order_item_id' => $poItem->id, 'qty' => 20],
            ],
        ]);
        $receiveResponse->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->on_order);

        $this->assertDatabaseHas('inbounds', [
            'reference_number' => $po->po_number,
            'type'             => 'PURCHASE_ORDER',
        ]);
    }

    public function test_po_partial_receive(): void
    {
        $createResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 40, 'unit_price' => 80000],
            ],
        ]);
        $poId = $createResponse->json('data.id');

        $this->postJson("/api/v1/purchase/orders/{$poId}/approve");

        $po = \Modules\Purchase\Models\PurchaseOrder::find($poId);
        $poItem = $po->items->first();

        $this->postJson("/api/v1/purchase/orders/{$poId}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['purchase_order_item_id' => $poItem->id, 'qty' => 15],
            ],
        ])->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(25, $this->inventory->on_order);

        $po->refresh();
        $this->assertEquals('PARTIAL_RECEIVED', $po->status);
    }

    // ═══════════════════════════════════════════════════════════
    // §3 — INBOUND → STOCK (receive adds on_hand)
    // ═══════════════════════════════════════════════════════════

    public function test_inbound_receive_adds_stock(): void
    {
        $createResponse = $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->location->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by'    => 'admin',
            'items'         => [
                ['item_id' => $this->variant->id, 'expected_qty' => 30],
            ],
        ]);
        $createResponse->assertStatus(201);
        $inboundId = $createResponse->json('data.id');
        $inboundItem = $createResponse->json('data.items.0');

        $receiveResponse = $this->postJson("/api/v1/inbounds/{$inboundId}/receive", [
            'received_by' => 'staff-gudang',
            'items'       => [
                ['inbound_item_id' => $inboundItem['id'], 'qty' => 30],
            ],
        ]);
        $receiveResponse->assertOk();

        $binInventory = Inventory::where('item_id', $this->variant->id)
            ->where('location_id', $this->location->id)
            ->where('bin_id', $this->binInbound->id)
            ->first();

        $this->assertNotNull($binInventory);
        $this->assertEquals(30, $binInventory->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source'  => 'ADJUSTMENT',
        ]);
    }

    public function test_inbound_partial_receive(): void
    {
        $createResponse = $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->location->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by'    => 'admin',
            'items'         => [
                ['item_id' => $this->variant->id, 'expected_qty' => 50],
            ],
        ]);
        $inboundId = $createResponse->json('data.id');
        $inboundItemId = $createResponse->json('data.items.0.id');

        $this->postJson("/api/v1/inbounds/{$inboundId}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['inbound_item_id' => $inboundItemId, 'qty' => 20],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('inbounds', [
            'id'     => $inboundId,
            'status' => 'PARTIAL',
        ]);

        $binInventory = Inventory::where('item_id', $this->variant->id)
            ->where('bin_id', $this->binInbound->id)
            ->first();
        $this->assertEquals(20, $binInventory->on_hand);
    }

    public function test_inbound_receive_rejects_over_expected(): void
    {
        $createResponse = $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->location->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by'    => 'admin',
            'items'         => [
                ['item_id' => $this->variant->id, 'expected_qty' => 10],
            ],
        ]);
        $inboundId = $createResponse->json('data.id');
        $inboundItemId = $createResponse->json('data.items.0.id');

        $response = $this->postJson("/api/v1/inbounds/{$inboundId}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['inbound_item_id' => $inboundItemId, 'qty' => 999],
            ],
        ]);

        $response->assertStatus(500);
    }

    // ═══════════════════════════════════════════════════════════
    // §4 — TRANSFER → STOCK (source deducts, dest adds)
    // ═══════════════════════════════════════════════════════════

    public function test_transfer_out_in_full_cycle(): void
    {
        $outResponse = $this->postJson('/api/v1/inventory/transfers', [
            'source_location_id'      => $this->location->id,
            'destination_location_id' => $this->location2->id,
            'created_by'              => 'admin',
            'items'                   => [
                ['item_id' => $this->variant->id, 'qty' => 25],
            ],
        ]);
        $outResponse->assertOk();
        $transferId = $outResponse->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(75, $this->inventory->on_hand);

        $this->postJson("/api/v1/inventory/transfers/{$transferId}/receive", [
            'received_by' => 'staff-cabang',
        ])->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(75, $this->inventory->on_hand);

        $destInventory = Inventory::where('item_id', $this->variant->id)
            ->where('location_id', $this->location2->id)
            ->first();

        $this->assertNotNull($destInventory);
        $this->assertEquals(25, $destInventory->on_hand);
        $this->assertEquals(25, $destInventory->available);

        $totalStock = Inventory::where('item_id', $this->variant->id)->sum('on_hand');
        $this->assertEquals(100, $totalStock);
    }

    // ═══════════════════════════════════════════════════════════
    // §5 — PURCHASE → INBOUND → STOCK (end-to-end)
    // ═══════════════════════════════════════════════════════════

    public function test_purchase_to_inbound_full_flow(): void
    {
        $poResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 50, 'unit_price' => 80000],
            ],
        ]);
        $poId = $poResponse->json('data.id');

        $this->postJson("/api/v1/purchase/orders/{$poId}/approve");

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
        $this->assertEquals(50, $this->inventory->on_order);
        $this->assertEquals(50, $this->inventory->available);

        $po = \Modules\Purchase\Models\PurchaseOrder::find($poId);
        $poItem = $po->items->first();

        $receiveResponse = $this->postJson("/api/v1/purchase/orders/{$poId}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['purchase_order_item_id' => $poItem->id, 'qty' => 50],
            ],
        ]);
        $receiveResponse->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->on_order);

        $inbound = \Modules\Inbound\Models\Inbound::where('reference_number', $po->po_number)->first();
        $this->assertNotNull($inbound);
        $this->assertEquals('PURCHASE_ORDER', $inbound->type);

        $inboundItem = $inbound->items->first();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['inbound_item_id' => $inboundItem->id, 'qty' => 50],
            ],
        ])->assertOk();

        $binInventory = Inventory::where('item_id', $this->variant->id)
            ->where('bin_id', $this->binInbound->id)
            ->first();
        $this->assertNotNull($binInventory);
        $this->assertEquals(50, $binInventory->on_hand);

        $totalOnHand = Inventory::where('item_id', $this->variant->id)->sum('on_hand');
        $this->assertEquals(150, $totalOnHand);
    }

    // ═══════════════════════════════════════════════════════════
    // §6 — SALES + PURCHASE COMBINED (stock balance)
    // ═══════════════════════════════════════════════════════════

    public function test_sales_and_purchase_stock_balance(): void
    {
        $poResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 30, 'unit_price' => 80000],
            ],
        ]);
        $poId = $poResponse->json('data.id');
        $this->postJson("/api/v1/purchase/orders/{$poId}/approve");

        $soResult = $this->createSalesOrder(20);
        $soId = $soResult['response']->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(100, $this->inventory->on_hand);
        $this->assertEquals(30, $this->inventory->on_order);
        $this->assertEquals(20, $this->inventory->reserved);
        // available = on_hand - on_order - reserved = 100 - 30 - 20 = 50
        $this->assertEquals(50, $this->inventory->available);
    }

    // ═══════════════════════════════════════════════════════════
    // §7 — DELETE ORDER → STOCK RESTORE
    // ═══════════════════════════════════════════════════════════

    public function test_delete_reserved_order_restores_stock(): void
    {
        $result = $this->createSalesOrder(12);
        $orderId = $result['response']->json('data.id');

        $this->inventory->refresh();
        $this->assertEquals(12, $this->inventory->reserved);

        $this->deleteJson("/api/v1/sales/{$orderId}")->assertOk();

        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->reserved);
        $this->assertEquals(100, $this->inventory->available);
    }

    // ═══════════════════════════════════════════════════════════
    // §8 — AVAILABLE FORMULA CONSISTENCY
    // ═══════════════════════════════════════════════════════════

    public function test_available_always_equals_formula_after_operations(): void
    {
        $poResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->variant->id, 'qty' => 40, 'unit_price' => 80000],
            ],
        ]);
        $poId = $poResponse->json('data.id');
        $this->postJson("/api/v1/purchase/orders/{$poId}/approve");

        $this->createSalesOrder(15);
        $this->createSalesOrder(10);

        $this->postJson('/api/v1/inventory/adjustments', [
            'item_id'     => $this->variant->id,
            'location_id' => $this->location->id,
            'qty'         => 20,
            'created_by'  => 'admin',
        ]);

        $this->inventory->refresh();
        $expected = $this->inventory->on_hand - $this->inventory->on_order - $this->inventory->reserved;
        $this->assertEquals($expected, $this->inventory->available);
    }
}
