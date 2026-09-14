<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class StockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private ProductVariant $variant;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        $this->actingAs($this->createPrivilegedUser(), 'sanctum');
        $this->seedInventory();
    }

    private function seedInventory(): void
    {
        $this->location = Location::create([
            'location_code' => 'WH-01',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $bin = LocationBin::create([
            'location_id' => $this->location->id,
            'floor_code' => 'F1',
            'row_code' => 'R1',
            'column_code' => 'C1',
            'bin_code' => 'F1-R1-C1',
            'bin_final_code' => 'WH01-F1-R1-C1',
            'is_inbound' => false,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Electronics',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Test Product',
            'sku' => 'TST-001',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'TST-001-BLK',
            'sell_price' => 100000,
            'is_active' => true,
        ]);

        $this->inventory = Inventory::create([
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $bin->id,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => 100,
            'on_order' => 0,
            'available' => 100,
        ]);
    }

    private function createSalesOrder(int $qty): array
    {
        $response = $this->postJson('/api/v1/sales', [
            'salesorder_no' => 'SO-'.uniqid(),
            'location_id' => $this->location->id,
            'customer_name' => 'Toko Maju',
            'transaction_date' => now()->toDateTimeString(),
            'sub_total' => $qty * 100000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 10000,
            'insurance_cost' => 0,
            'grand_total' => ($qty * 100000) + 10000,
            'shipping_full_name' => 'Budi',
            'shipping_phone' => '081234567890',
            'shipping_address' => 'Jl. Test',
            'shipping_city' => 'Jakarta',
            'shipping_province' => 'DKI Jakarta',
            'shipping_post_code' => '12345',
            'shipping_country' => 'Indonesia',
            'payment_method' => 'bank_transfer',
            'source' => 'manual',
            'items' => [[
                'sku' => $this->variant->sku,
                'description' => 'Test Product Black',
                'qty_in_base' => $qty,
                'price' => 100000,
                'amount' => $qty * 100000,
            ]],
        ]);

        return ['response' => $response, 'order_id' => $response->json('data.id')];
    }

    public function test_sales_order_reserves_stock_in_a_final_bin(): void
    {
        $result = $this->createSalesOrder(10);
        $result['response']->assertCreated();

        $this->inventory->refresh();

        $this->assertSame(100, (int) $this->inventory->on_hand);
        $this->assertSame(10, (int) $this->inventory->on_order);
        $this->assertSame(90, (int) $this->inventory->available);
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source' => 'ORDER_RESERVE',
        ]);
    }

    public function test_cancelling_a_reserved_order_releases_stock(): void
    {
        $orderId = $this->createSalesOrder(15)['order_id'];

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'cancelled'])
            ->assertOk();

        $this->inventory->refresh();

        $this->assertSame(100, (int) $this->inventory->on_hand);
        $this->assertSame(0, (int) $this->inventory->on_order);
        $this->assertSame(100, (int) $this->inventory->available);
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source' => 'ORDER_RELEASE',
        ]);
    }

    public function test_direct_pick_status_releases_reservation_without_physical_deduction(): void
    {
        $orderId = $this->createSalesOrder(8)['order_id'];

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked'])
            ->assertOk();

        $this->inventory->refresh();

        $this->assertSame(100, (int) $this->inventory->on_hand);
        $this->assertSame(0, (int) $this->inventory->on_order);
        $this->assertSame(100, (int) $this->inventory->available);
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variant->id,
            'source' => 'ORDER_RELEASE',
        ]);
        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $this->variant->id,
            'source' => 'INVOICE',
        ]);
    }

    public function test_direct_shipment_status_does_not_write_a_second_stock_movement(): void
    {
        $orderId = $this->createSalesOrder(5)['order_id'];

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked'])->assertOk();
        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'packed'])->assertOk();
        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'shipped'])->assertOk();

        $this->inventory->refresh();

        $this->assertSame(100, (int) $this->inventory->on_hand);
        $this->assertSame(0, (int) $this->inventory->on_order);
        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $this->variant->id,
            'source' => 'ORDER_SHIP',
        ]);
    }

    public function test_cancelling_after_a_direct_pick_does_not_restore_uncommitted_stock(): void
    {
        $orderId = $this->createSalesOrder(10)['order_id'];

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked'])->assertOk();
        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'cancelled'])->assertOk();

        $this->inventory->refresh();

        $this->assertSame(100, (int) $this->inventory->on_hand);
        $this->assertSame(0, (int) $this->inventory->on_order);
        $this->assertSame(100, (int) $this->inventory->available);
        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $this->variant->id,
            'source' => 'INVOICE',
        ]);
    }

    public function test_multiple_orders_accumulate_the_reservation(): void
    {
        $this->createSalesOrder(10);
        $this->createSalesOrder(20);
        $this->createSalesOrder(5);

        $this->inventory->refresh();

        $this->assertSame(100, (int) $this->inventory->on_hand);
        $this->assertSame(35, (int) $this->inventory->on_order);
        $this->assertSame(65, (int) $this->inventory->available);
    }

    public function test_direct_status_lifecycle_keeps_inventory_balanced(): void
    {
        $orderId = $this->createSalesOrder(10)['order_id'];

        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'picked'])->assertOk();
        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'packed'])->assertOk();
        $this->putJson("/api/v1/sales/{$orderId}", ['status' => 'shipped'])->assertOk();

        $this->inventory->refresh();

        $this->assertSame(100, (int) $this->inventory->on_hand);
        $this->assertSame(0, (int) $this->inventory->on_order);
        $this->assertSame(100, (int) $this->inventory->available);
    }

    public function test_deleting_a_reserved_order_releases_stock(): void
    {
        $orderId = $this->createSalesOrder(12)['order_id'];

        $this->deleteJson("/api/v1/sales/{$orderId}")->assertOk();

        $this->inventory->refresh();

        $this->assertSame(0, (int) $this->inventory->on_order);
        $this->assertSame(100, (int) $this->inventory->available);
    }
}
