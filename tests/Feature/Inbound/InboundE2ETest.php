<?php

namespace Tests\Feature\Inbound;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Inventory\Models\Inventory;
use Modules\Supplier\Models\Supplier;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Inbound\Models\Inbound;
use Modules\Order\Models\Order;
use App\Models\User;
use Tests\TestCase;

class InboundE2ETest extends TestCase
{
    use RefreshDatabase;

    private Location $warehouse;
    private Location $warehouse2;
    private LocationBin $inboundBin;
    private LocationBin $storageBin1;
    private LocationBin $storageBin2;
    private Product $product1;
    private Product $product2;
    private Product $product3;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        $this->warehouse = Location::create([
            'location_code' => 'WH-01',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $this->warehouse2 = Location::create([
            'location_code' => 'WH-02',
            'location_name' => 'Gudang Cabang',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $this->inboundBin = LocationBin::create([
            'location_id'    => $this->warehouse->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R0',
            'column_code'    => 'C0',
            'bin_code'       => 'INB',
            'bin_final_code' => 'F1-R0-C0-INB',
            'max_qty'        => 0,
            'is_inbound'     => true,
        ]);

        $this->storageBin1 = LocationBin::create([
            'location_id'    => $this->warehouse->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R1',
            'column_code'    => 'C1',
            'bin_code'       => 'B1',
            'bin_final_code' => 'F1-R1-C1-B1',
            'max_qty'        => 500,
            'is_inbound'     => false,
        ]);

        $this->storageBin2 = LocationBin::create([
            'location_id'    => $this->warehouse->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R1',
            'column_code'    => 'C2',
            'bin_code'       => 'B1',
            'bin_final_code' => 'F1-R1-C2-B1',
            'max_qty'        => 500,
            'is_inbound'     => false,
        ]);

        LocationBin::create([
            'location_id'    => $this->warehouse2->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R0',
            'column_code'    => 'C0',
            'bin_code'       => 'INB',
            'bin_final_code' => 'F1-R0-C0-INB',
            'max_qty'        => 0,
            'is_inbound'     => true,
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'name' => 'Test', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->product1 = Product::create([
            'category_id' => $categoryId, 'name' => 'Laptop Test', 'sku' => 'LAP-001', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $this->product1->id, 'sku' => 'LAP-001-V1', 'sell_price' => 7000000, 'is_active' => true,
        ]);

        $this->product2 = Product::create([
            'category_id' => $categoryId, 'name' => 'Mouse Test', 'sku' => 'MOU-001', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $this->product2->id, 'sku' => 'MOU-001-V1', 'sell_price' => 500000, 'is_active' => true,
        ]);

        $this->product3 = Product::create([
            'category_id' => $categoryId, 'name' => 'Keyboard Test', 'sku' => 'KBD-001', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $this->product3->id, 'sku' => 'KBD-001-V1', 'sell_price' => 1000000, 'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'code' => 'SUP-01', 'name' => 'Test Supplier', 'status' => 'active',
        ]);

        // Stock in warehouse2 for transfer tests
        foreach ([$this->product1, $this->product2] as $p) {
            Inventory::create([
                'item_id' => $p->id, 'location_id' => $this->warehouse2->id,
                'bin_id' => null, 'batch_no' => '', 'serial_no' => '',
                'on_hand' => 100, 'on_order' => 0, 'reserved' => 0, 'available' => 100,
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // [A] CREATE PO
    // ═══════════════════════════════════════════════════════════

    public function test_a_create_po_returns_201(): void
    {
        $response = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id'  => $this->supplier->id,
            'location_id'  => $this->warehouse->id,
            'order_date'   => now()->toDateString(),
            'expected_date' => now()->addDays(7)->toDateString(),
            'created_by'   => 'admin',
            'items'        => [
                ['item_id' => $this->product1->id, 'qty' => 10, 'unit_price' => 7000000],
                ['item_id' => $this->product2->id, 'qty' => 20, 'unit_price' => 500000],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('purchase_orders', ['status' => 'DRAFT']);
    }

    public function test_a_approve_po_changes_status_to_open(): void
    {
        $po = $this->createPO();

        $response = $this->postJson("/api/v1/purchase/orders/{$po->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'OPEN');
    }

    public function test_a_cancel_po(): void
    {
        $po = $this->createPO();

        $this->postJson("/api/v1/purchase/orders/{$po->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_a_delete_draft_po(): void
    {
        $po = $this->createPO();

        $this->deleteJson("/api/v1/purchase/orders/{$po->id}")->assertOk();
        $this->assertDatabaseMissing('purchase_orders', ['id' => $po->id]);
    }

    public function test_a_cannot_delete_open_po(): void
    {
        $po = $this->createPO();
        $this->postJson("/api/v1/purchase/orders/{$po->id}/approve");

        $this->deleteJson("/api/v1/purchase/orders/{$po->id}")->assertStatus(500);
    }

    // ═══════════════════════════════════════════════════════════
    // [B] RECEIVE FROM PO → INBOUND GRN
    // ═══════════════════════════════════════════════════════════

    public function test_b_receive_from_po_creates_inbound(): void
    {
        $po = $this->createAndApprovePO();
        $poItems = PurchaseOrderItem::where('purchase_order_id', $po->id)->get();

        $response = $this->postJson("/api/v1/purchase/orders/{$po->id}/receive", [
            'received_by' => 'warehouse_staff',
            'items'       => [
                ['purchase_order_item_id' => $poItems[0]->id, 'qty' => 10],
                ['purchase_order_item_id' => $poItems[1]->id, 'qty' => 20],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id, 'status' => 'FULLY_RECEIVED',
        ]);
        $this->assertDatabaseHas('inbounds', [
            'reference_number' => $po->po_number,
            'type'             => 'PURCHASE_ORDER',
            'source_type'      => 'purchase_order',
        ]);
    }

    public function test_b_partial_receive_po(): void
    {
        $po = $this->createAndApprovePO();
        $poItems = PurchaseOrderItem::where('purchase_order_id', $po->id)->get();

        $this->postJson("/api/v1/purchase/orders/{$po->id}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['purchase_order_item_id' => $poItems[0]->id, 'qty' => 5],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id, 'status' => 'PARTIAL_RECEIVED',
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $poItems[0]->id, 'received_qty' => 5,
        ]);
    }

    public function test_b_cannot_receive_over_qty(): void
    {
        $po = $this->createAndApprovePO();
        $poItems = PurchaseOrderItem::where('purchase_order_id', $po->id)->get();

        $this->postJson("/api/v1/purchase/orders/{$po->id}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['purchase_order_item_id' => $poItems[0]->id, 'qty' => 999],
            ],
        ])->assertStatus(500);
    }

    // ═══════════════════════════════════════════════════════════
    // [C] RECEIVE TRANSFER
    // ═══════════════════════════════════════════════════════════

    public function test_c_transfer_out_deducts_source_stock(): void
    {
        $response = $this->postJson('/api/v1/inventory/transfers', [
            'source_location_id'      => $this->warehouse2->id,
            'destination_location_id' => $this->warehouse->id,
            'created_by'              => 'admin',
            'items'                   => [
                ['item_id' => $this->product1->id, 'qty' => 15],
            ],
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals('IN_TRANSIT', $data['status']);

        $inv = Inventory::where('item_id', $this->product1->id)
            ->where('location_id', $this->warehouse2->id)->first();
        $this->assertEquals(85, $inv->on_hand);
    }

    public function test_c_transfer_in_adds_destination_stock(): void
    {
        $transfer = $this->createTransferOut();

        $response = $this->postJson("/api/v1/inventory/transfers/{$transfer->id}/receive", [
            'received_by' => 'warehouse_staff',
        ]);

        $response->assertOk();
        $this->assertEquals('RECEIVED', $response->json('data.status'));

        $destInv = Inventory::where('item_id', $this->product1->id)
            ->where('location_id', $this->warehouse->id)->first();
        $this->assertNotNull($destInv);
        $this->assertEquals(15, $destInv->on_hand);

        $this->assertDatabaseHas('inbounds', [
            'type'        => 'TRANSIT_IN',
            'source_type' => 'transfer',
        ]);
    }

    public function test_c_transit_list_shows_in_transit(): void
    {
        $this->createTransferOut();

        $response = $this->getJson('/api/v1/inventory/transfers/transit');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
    }

    // ═══════════════════════════════════════════════════════════
    // [D] SALES RETURN WITH ORDER
    // ═══════════════════════════════════════════════════════════

    public function test_d_create_sales_return_with_order(): void
    {
        $order = Order::create([
            'salesorder_no'    => 'SO-RET-001',
            'customer_name'    => 'Customer Retur',
            'status'           => 'shipped',
            'transaction_date' => now(),
            'grand_total'      => 500000,
        ]);

        $response = $this->postJson('/api/v1/sales/returns', [
            'order_id'    => $order->id,
            'location_id' => $this->warehouse->id,
            'source'      => 'manual',
            'customer_name' => 'Customer Retur',
            'reason'      => 'Barang cacat',
            'created_by'  => 'cs_admin',
            'items'       => [
                ['item_id' => $this->product1->id, 'qty' => 1, 'condition' => 'DAMAGE'],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.order_id', $order->id);
    }

    // ═══════════════════════════════════════════════════════════
    // [E] SALES RETURN WITHOUT ORDER
    // ═══════════════════════════════════════════════════════════

    public function test_e_create_sales_return_without_order(): void
    {
        $response = $this->postJson('/api/v1/sales/returns', [
            'location_id'   => $this->warehouse->id,
            'customer_name' => 'Walk-in Customer',
            'reason'        => 'Tidak sesuai',
            'created_by'    => 'cs_admin',
            'items'         => [
                ['item_id' => $this->product2->id, 'qty' => 3, 'condition' => 'GOOD'],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.order_id', null);
    }

    // ═══════════════════════════════════════════════════════════
    // [F] MARKETPLACE RETURN — ACCEPT / REJECT / COMPLETE
    // ═══════════════════════════════════════════════════════════

    public function test_f_accept_marketplace_return_creates_inbound(): void
    {
        $ret = $this->createMarketplaceReturn();

        $response = $this->postJson("/api/v1/sales/returns/{$ret->id}/accept", [
            'processed_by' => 'warehouse_staff',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED');

        $this->assertDatabaseHas('inbounds', [
            'type'        => 'SALES_RETURN',
            'source_type' => 'sales_return',
        ]);
    }

    public function test_f_reject_marketplace_return(): void
    {
        $ret = $this->createMarketplaceReturn();

        $response = $this->postJson("/api/v1/sales/returns/{$ret->id}/reject", [
            'processed_by' => 'warehouse_staff',
            'reason'       => 'Barang tidak sesuai klaim',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');

        $this->assertDatabaseMissing('inbounds', ['source_type' => 'sales_return']);
    }

    public function test_f_complete_marketplace_return(): void
    {
        $ret = $this->createMarketplaceReturn();
        $this->postJson("/api/v1/sales/returns/{$ret->id}/accept", ['processed_by' => 'staff']);

        $response = $this->postJson("/api/v1/sales/returns/{$ret->id}/complete", [
            'processed_by' => 'admin',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_f_unprocessed_list(): void
    {
        $this->createMarketplaceReturn();
        $this->createMarketplaceReturn('MKT-RET-002');

        $response = $this->getJson('/api/v1/sales/returns/unprocessed');

        $response->assertOk();
        $this->assertEquals(2, $response->json('meta.total'));
    }

    // ═══════════════════════════════════════════════════════════
    // [G] CONSIGNMENT
    // ═══════════════════════════════════════════════════════════

    public function test_g_create_consignment_inbound(): void
    {
        $response = $this->postJson('/api/v1/inbounds', [
            'location_id'    => $this->warehouse->id,
            'type'           => 'CONSIGNMENT',
            'source_type'    => 'consignment',
            'expected_date'  => now()->addDays(3)->toDateString(),
            'created_by'     => 'admin',
            'items'          => [
                ['item_id' => $this->product3->id, 'expected_qty' => 50],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'CONSIGNMENT')
            ->assertJsonPath('data.status', 'DRAFT');
    }

    // ═══════════════════════════════════════════════════════════
    // [H] AUTO PUTAWAY
    // ═══════════════════════════════════════════════════════════

    public function test_h_auto_putaway_full_flow(): void
    {
        $inbound = $this->createAndReceiveInbound();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/close-receiving", [
            'closed_by' => 'admin',
        ])->assertOk();

        $response = $this->postJson("/api/v1/inbounds/{$inbound->id}/auto-putaway", [
            'created_by' => 'auto_system',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('inventory_movements', [
            'source' => 'PUTAWAY_OUT',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'source' => 'PUTAWAY_IN',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // [I] + [J] MANUAL PUTAWAY
    // ═══════════════════════════════════════════════════════════

    public function test_ij_manual_putaway_to_specific_bin(): void
    {
        $inbound = $this->createAndReceiveInbound();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/close-receiving", [
            'closed_by' => 'admin',
        ])->assertOk();

        $inboundItems = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->get();

        $response = $this->postJson("/api/v1/inbounds/{$inbound->id}/putaway", [
            'created_by'    => 'staff_putaway',
            'putaway_items' => [
                [
                    'inbound_item_id'  => $inboundItems[0]->id,
                    'destination_bin_id' => $this->storageBin1->id,
                    'qty'              => 5,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'PUTAWAY_IN_PROGRESS');

        $this->postJson("/api/v1/inbounds/{$inbound->id}/putaway", [
            'created_by'    => 'staff_putaway',
            'putaway_items' => [
                [
                    'inbound_item_id'  => $inboundItems[0]->id,
                    'destination_bin_id' => $this->storageBin2->id,
                    'qty'              => 5,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $storageBin1Inv = Inventory::where('bin_id', $this->storageBin1->id)
            ->where('item_id', $this->product1->id)->first();
        $storageBin2Inv = Inventory::where('bin_id', $this->storageBin2->id)
            ->where('item_id', $this->product1->id)->first();

        $this->assertEquals(5, $storageBin1Inv->on_hand);
        $this->assertEquals(5, $storageBin2Inv->on_hand);
    }

    public function test_ij_cannot_putaway_more_than_received(): void
    {
        $inbound = $this->createAndReceiveInbound();
        $this->postJson("/api/v1/inbounds/{$inbound->id}/close-receiving", ['closed_by' => 'admin']);

        $inboundItemId = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->first()->id;

        $this->postJson("/api/v1/inbounds/{$inbound->id}/putaway", [
            'created_by'    => 'staff',
            'putaway_items' => [
                [
                    'inbound_item_id'  => $inboundItemId,
                    'destination_bin_id' => $this->storageBin1->id,
                    'qty'              => 999,
                ],
            ],
        ])->assertStatus(500);
    }

    public function test_ij_pending_putaway_endpoint(): void
    {
        $inbound = $this->createAndReceiveInbound();
        $this->postJson("/api/v1/inbounds/{$inbound->id}/close-receiving", ['closed_by' => 'admin']);

        $response = $this->getJson("/api/v1/inbounds/{$inbound->id}/pending-putaway");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    // ═══════════════════════════════════════════════════════════
    // FULL E2E: PO → RECEIVE → PUTAWAY → STOCK CHECK
    // ═══════════════════════════════════════════════════════════

    public function test_full_e2e_po_to_putaway(): void
    {
        // 1. Create PO
        $poResponse = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->product1->id, 'qty' => 20, 'unit_price' => 7000000],
            ],
        ]);
        $poId = $poResponse->json('data.id');

        // 2. Approve PO
        $this->postJson("/api/v1/purchase/orders/{$poId}/approve")->assertOk();

        // 3. Receive from PO
        $poItemId = PurchaseOrderItem::where('purchase_order_id', $poId)->first()->id;
        $receiveResponse = $this->postJson("/api/v1/purchase/orders/{$poId}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['purchase_order_item_id' => $poItemId, 'qty' => 20],
            ],
        ]);
        $receiveResponse->assertOk();

        // PO fully received
        $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'status' => 'FULLY_RECEIVED']);

        // Inbound created (DRAFT because it needs receive step)
        $inbound = Inbound::where('reference_number', PurchaseOrder::find($poId)->po_number)->first();
        $this->assertNotNull($inbound);
        $this->assertEquals('DRAFT', $inbound->status);

        // 4. Receive on inbound
        $inboundItemId = \DB::table('inbound_items')->where('inbound_id', $inbound->id)->first()->id;
        $this->postJson("/api/v1/inbounds/{$inbound->id}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['inbound_item_id' => $inboundItemId, 'qty' => 20],
            ],
        ])->assertOk();

        // Stock at inbound bin
        $inboundStock = Inventory::where('item_id', $this->product1->id)
            ->where('bin_id', $this->inboundBin->id)->first();
        $this->assertEquals(20, $inboundStock->on_hand);

        // 5. Auto-putaway
        $this->postJson("/api/v1/inbounds/{$inbound->id}/auto-putaway", [
            'created_by' => 'auto_system',
        ])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        // Stock moved from inbound bin to storage bin
        $inboundStock->refresh();
        $this->assertEquals(0, $inboundStock->on_hand);

        $storageStock = Inventory::where('item_id', $this->product1->id)
            ->where('bin_id', $this->storageBin1->id)->first();
        $this->assertEquals(20, $storageStock->on_hand);

        // Movements audit trail
        $this->assertDatabaseHas('inventory_movements', ['source' => 'ADJUSTMENT']);
        $this->assertDatabaseHas('inventory_movements', ['source' => 'PUTAWAY_OUT']);
        $this->assertDatabaseHas('inventory_movements', ['source' => 'PUTAWAY_IN']);
    }

    // ═══════════════════════════════════════════════════════════
    // INBOUND CRUD & STATUS
    // ═══════════════════════════════════════════════════════════

    public function test_inbound_list(): void
    {
        $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->warehouse->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by'    => 'admin',
            'items'         => [['item_id' => $this->product1->id, 'expected_qty' => 10]],
        ])->assertStatus(201);

        $this->getJson('/api/v1/inbounds')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_inbound_cancel(): void
    {
        $inbound = $this->createDraftInbound();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_cannot_cancel_completed_inbound(): void
    {
        $inbound = $this->createAndReceiveInbound();
        $this->postJson("/api/v1/inbounds/{$inbound->id}/close-receiving", ['closed_by' => 'admin']);
        $this->postJson("/api/v1/inbounds/{$inbound->id}/auto-putaway", ['created_by' => 'admin']);

        $this->postJson("/api/v1/inbounds/{$inbound->id}/cancel")->assertStatus(500);
    }

    public function test_discrepancy_recorded_on_close(): void
    {
        $inbound = $this->createDraftInbound(expectedQty: 100);

        $inboundItemId = \DB::table('inbound_items')->where('inbound_id', $inbound->id)->first()->id;

        // Receive only 80 of 100
        $this->postJson("/api/v1/inbounds/{$inbound->id}/receive", [
            'received_by' => 'staff',
            'items'       => [['inbound_item_id' => $inboundItemId, 'qty' => 80]],
        ])->assertOk();

        // Close receiving
        $this->postJson("/api/v1/inbounds/{$inbound->id}/close-receiving", [
            'closed_by' => 'admin',
        ])->assertOk();

        $this->assertDatabaseHas('inbound_items', [
            'id'              => $inboundItemId,
            'received_qty'    => 80,
            'discrepancy_qty' => 20,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // SUPPLIER CRUD
    // ═══════════════════════════════════════════════════════════

    public function test_supplier_crud(): void
    {
        // Create
        $create = $this->postJson('/api/v1/suppliers', [
            'name'  => 'Supplier Baru',
            'email' => 'baru@supplier.com',
            'phone' => '08111222333',
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        // Read
        $this->getJson("/api/v1/suppliers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Supplier Baru');

        // Update
        $this->putJson("/api/v1/suppliers/{$id}", ['name' => 'Supplier Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Supplier Updated');

        // List
        $this->getJson('/api/v1/suppliers')
            ->assertOk();

        // Delete
        $this->deleteJson("/api/v1/suppliers/{$id}")->assertOk();
        $this->assertDatabaseMissing('suppliers', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════

    private function createPO(): PurchaseOrder
    {
        $response = $this->postJson('/api/v1/purchase/orders', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'order_date'  => now()->toDateString(),
            'created_by'  => 'admin',
            'items'       => [
                ['item_id' => $this->product1->id, 'qty' => 10, 'unit_price' => 7000000],
                ['item_id' => $this->product2->id, 'qty' => 20, 'unit_price' => 500000],
            ],
        ]);

        return PurchaseOrder::find($response->json('data.id'));
    }

    private function createAndApprovePO(): PurchaseOrder
    {
        $po = $this->createPO();
        $this->postJson("/api/v1/purchase/orders/{$po->id}/approve");
        return $po->fresh();
    }

    private function createTransferOut()
    {
        $response = $this->postJson('/api/v1/inventory/transfers', [
            'source_location_id'      => $this->warehouse2->id,
            'destination_location_id' => $this->warehouse->id,
            'created_by'              => 'admin',
            'items'                   => [
                ['item_id' => $this->product1->id, 'qty' => 15],
            ],
        ]);

        return \Modules\Inventory\Models\InventoryTransfer::find($response->json('data.id'));
    }

    private function createDraftInbound(int $expectedQty = 10): Inbound
    {
        $response = $this->postJson('/api/v1/inbounds', [
            'location_id'   => $this->warehouse->id,
            'type'          => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by'    => 'admin',
            'items'         => [
                ['item_id' => $this->product1->id, 'expected_qty' => $expectedQty],
            ],
        ]);

        return Inbound::find($response->json('data.id'));
    }

    private function createAndReceiveInbound(): Inbound
    {
        $inbound = $this->createDraftInbound();

        $inboundItemId = \DB::table('inbound_items')
            ->where('inbound_id', $inbound->id)->first()->id;

        $this->postJson("/api/v1/inbounds/{$inbound->id}/receive", [
            'received_by' => 'staff',
            'items'       => [
                ['inbound_item_id' => $inboundItemId, 'qty' => 10],
            ],
        ]);

        return $inbound->fresh();
    }

    private function createMarketplaceReturn(string $returnNumber = 'MKT-RET-001')
    {
        $response = $this->postJson('/api/v1/sales/returns', [
            'location_id'   => $this->warehouse->id,
            'source'        => 'marketplace',
            'customer_name' => 'Tokopedia Buyer',
            'reason'        => 'Barang rusak saat pengiriman',
            'created_by'    => 'marketplace_webhook',
            'items'         => [
                ['item_id' => $this->product2->id, 'qty' => 2, 'condition' => 'DAMAGE'],
            ],
        ]);

        return \Modules\Sales\Models\SalesReturn::find($response->json('data.id'));
    }
}
