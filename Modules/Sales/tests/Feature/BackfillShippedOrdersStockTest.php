<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Services\BackfillShippedOrdersStockService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class BackfillShippedOrdersStockTest extends TestCase
{
    use RefreshDatabase;

    private string $kecilLocationId;
    private string $binId;
    private string $productId;
    private string $itemId;
    private string $sku = 'TEST-CASE-SKU';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $existing = DB::table('locations')->where('location_code', Location::SYSTEM_KECIL_CODE)->value('id');
        if ($existing) {
            $this->kecilLocationId = $existing;
        } else {
            $this->kecilLocationId = Str::uuid()->toString();
            DB::table('locations')->insert([
                'id' => $this->kecilLocationId,
                'location_code' => Location::SYSTEM_KECIL_CODE,
                'location_name' => 'Gudang Kecil',
                'location_type' => 'WAREHOUSE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->binId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $this->binId,
            'location_id' => $this->kecilLocationId,
            'bin_code' => 'RAK-A1',
            'bin_final_code' => 'RAK-A1',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $this->productId,
            'name' => 'Test Product',
            'category_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_dry_run_does_not_mutate_database(): void
    {

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 50,
            'available' => 50,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = SalesOrder::create([
            'salesorder_no' => 'SO-TEST-001',
            'channel_order_no' => 'CH-TEST-001',
            'source' => 'tiktok',
            'status' => 'shipped',
            'channel_status' => 'SHIPPED',
            'location_id' => $this->kecilLocationId,
            'is_shadow' => false,
            'is_canceled' => false,
            'created_at' => now()->subDay(),
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'qty_in_base' => 2,
            'qty' => 2,
            'unit_price' => 10000,
        ]);

        $this->artisan('orders:backfill-shipped-stock --dry-run')
            ->expectsOutputToContain('1 pesanan yang belum potong stok fisik')
            ->expectsOutputToContain('[DRY-RUN SELESAI]')
            ->assertSuccessful();

        $this->assertEquals(50, DB::table('inventories')->where('item_id', $this->itemId)->value('on_hand'));
        $this->assertEquals(0, DB::table('inventory_movements')->count());
        $this->assertEquals(0, DB::table('order_bin_allocations')->count());
    }

    public function test_real_run_deducts_stock_and_is_idempotent(): void
    {

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 10,
            'available' => 10,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = SalesOrder::create([
            'salesorder_no' => 'SO-TEST-002',
            'channel_order_no' => 'CH-TEST-002',
            'source' => 'tiktok',
            'status' => 'shipped',
            'channel_status' => 'SHIPPED',
            'location_id' => $this->kecilLocationId,
            'is_shadow' => false,
            'is_canceled' => false,
            'created_at' => now()->subDay(),
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'qty_in_base' => 3,
            'qty' => 3,
            'unit_price' => 10000,
        ]);

        $this->artisan('orders:backfill-shipped-stock')
            ->expectsOutputToContain('[BACKFILL SELESAI]: 1 pesanan berhasil diproses (1 mutasi baris rak), 0 gagal.')
            ->assertSuccessful();

        $this->assertEquals(7, (int) DB::table('inventories')->where('item_id', $this->itemId)->value('on_hand'));

        $movement = DB::table('inventory_movements')->where('transaction_number', 'SO-TEST-002')->first();
        $this->assertNotNull($movement);
        $this->assertEquals(-3, (int) $movement->qty);
        $this->assertEquals(7, (int) $movement->balance);
        $this->assertEquals('ORDER_COMPLETE_OUT', $movement->source);
        $this->assertEquals('system:backfill', $movement->created_by);

        $allocation = DB::table('order_bin_allocations')->where('order_id', $order->id)->first();
        $this->assertNotNull($allocation);
        $this->assertEquals($this->binId, $allocation->bin_id);
        $this->assertEquals(3, (int) $allocation->qty);

        $this->artisan('orders:backfill-shipped-stock')
            ->expectsOutputToContain('Tidak ada pesanan yang perlu di-backfill')
            ->assertSuccessful();

        $this->assertEquals(7, (int) DB::table('inventories')->where('item_id', $this->itemId)->value('on_hand'));
        $this->assertEquals(1, DB::table('inventory_movements')->count());
    }

    public function test_backfill_never_consumes_an_inbound_default_bin(): void
    {
        $inboundBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $inboundBinId,
            'location_id' => $this->kecilLocationId,
            'bin_code' => 'INBOUND-STAGING',
            'bin_final_code' => 'INBOUND-STAGING',
            'is_inbound' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $inboundBinId,
            'on_hand' => 50,
            'available' => 0,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = SalesOrder::create([
            'salesorder_no' => 'SO-INBOUND-BLOCKED',
            'channel_order_no' => 'CH-INBOUND-BLOCKED',
            'source' => 'tiktok',
            'status' => 'shipped',
            'channel_status' => 'SHIPPED',
            'is_shadow' => false,
            'is_canceled' => false,
            'created_at' => now()->subDay(),
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'qty_in_base' => 2,
            'qty' => 2,
            'unit_price' => 10000,
        ]);

        $result = app(BackfillShippedOrdersStockService::class)->backfillOrder($order);

        $this->assertFalse($result['success']);
        $this->assertSame(2, $result['shortages'][0]['qty_short']);
        $this->assertSame(50, (int) DB::table('inventories')->where('bin_id', $inboundBinId)->value('on_hand'));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('order_bin_allocations', 0);
    }

    public function test_order_already_handed_to_local_warehouse_is_not_backfilled(): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 10,
            'available' => 10,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = SalesOrder::create([
            'salesorder_no' => 'SO-LOCAL-WMS-001',
            'channel_order_no' => 'CH-LOCAL-WMS-001',
            'source' => 'tiktok',
            'status' => 'shipped',
            'channel_status' => 'SHIPPED',
            'location_id' => $this->kecilLocationId,
            'handed_to_warehouse_at' => now(),
            'is_shadow' => false,
            'is_canceled' => false,
            'created_at' => now()->subDay(),
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'qty_in_base' => 2,
            'qty' => 2,
            'unit_price' => 10000,
        ]);

        $this->artisan('orders:backfill-shipped-stock --dry-run')
            ->expectsOutputToContain('Tidak ada pesanan yang perlu di-backfill')
            ->assertSuccessful();

        $this->assertEquals(10, DB::table('inventories')->where('item_id', $this->itemId)->value('on_hand'));
        $this->assertEquals(0, DB::table('inventory_movements')->count());
        $this->assertEquals(0, DB::table('order_bin_allocations')->count());
    }

    public function test_channel_backfill_always_consumes_from_gudang_kecil_not_order_location(): void
    {
        $otherLocationId = Str::uuid()->toString();
        $otherBinId = Str::uuid()->toString();

        DB::table('locations')->insert([
            'id' => $otherLocationId,
            'location_code' => 'WH-OTHER',
            'location_name' => 'Gudang Lain',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('location_bins')->insert([
            'id' => $otherBinId,
            'location_id' => $otherLocationId,
            'bin_code' => 'OTHER-A1',
            'bin_final_code' => 'OTHER-A1',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 10,
            'available' => 10,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $otherLocationId,
            'bin_id' => $otherBinId,
            'on_hand' => 100,
            'available' => 100,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = SalesOrder::create([
            'salesorder_no' => 'SO-TEST-KCL-001',
            'channel_order_no' => 'CH-TEST-KCL-001',
            'source' => 'tiktok',
            'status' => 'shipped',
            'channel_status' => 'SHIPPED',
            'location_id' => $otherLocationId,
            'is_shadow' => false,
            'is_canceled' => false,
            'created_at' => now()->subDay(),
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'qty_in_base' => 2,
            'qty' => 2,
            'unit_price' => 10000,
        ]);

        $this->artisan('orders:backfill-shipped-stock')->assertSuccessful();

        $this->assertSame(8, (int) DB::table('inventories')
            ->where('location_id', $this->kecilLocationId)
            ->value('on_hand'));
        $this->assertSame(100, (int) DB::table('inventories')
            ->where('location_id', $otherLocationId)
            ->value('on_hand'));
        $this->assertSame($this->kecilLocationId, DB::table('inventory_movements')
            ->where('transaction_number', 'SO-TEST-KCL-001')
            ->value('location_id'));
    }

    public function test_backfill_does_not_create_negative_stock_when_final_bin_is_empty(): void
    {

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 0,
            'available' => 0,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = SalesOrder::create([
            'salesorder_no' => 'SO-TEST-003',
            'channel_order_no' => 'CH-TEST-003',
            'source' => 'tiktok',
            'status' => 'shipped',
            'channel_status' => 'COMPLETED',
            'location_id' => $this->kecilLocationId,
            'is_shadow' => false,
            'is_canceled' => false,
            'created_at' => now()->subDay(),
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'qty_in_base' => 5,
            'qty' => 5,
            'unit_price' => 10000,
        ]);

        $this->artisan('orders:backfill-shipped-stock')
            ->assertSuccessful();

        $this->assertEquals(0, (int) DB::table('inventories')->where('item_id', $this->itemId)->value('on_hand'));
        $this->assertDatabaseMissing('inventory_movements', ['transaction_number' => 'SO-TEST-003']);
        $this->assertDatabaseMissing('order_bin_allocations', ['order_id' => $order->id]);
    }

    public function test_upsert_from_channel_with_shipped_status_automatically_deducts_stock_synchronously(): void
    {
        config()->set('inventory.channel_auto_physical_backfill', true);

        $channelId = DB::table('channels')->where('code', 'tiktok')->value('id')
            ?? DB::table('channels')->insertGetId([
                'id' => Str::uuid()->toString(),
                'code' => 'tiktok',
                'name' => 'TikTok Shop',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $shopId = Str::uuid()->toString();
        DB::table('channel_shops')->insert([
            'id' => $shopId,
            'channel_id' => $channelId,
            'shop_id' => 'shop_123',
            'shop_name' => 'Test Shop',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pcmId = Str::uuid()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcmId,
            'product_id' => $this->productId,
            'channel_shop_id' => $shopId,
            'external_product_id' => 'EXT-123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_variant_channel_mappings')->insert([
            'id' => Str::uuid()->toString(),
            'product_channel_mapping_id' => $pcmId,
            'variant_id' => $this->itemId,
            'external_sku_id' => 'EXT-SKU-123',
            'channel_seller_sku' => $this->sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 20,
            'available' => 20,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderPayload = [
            'salesorder_no' => 'SO-AUTO-001',
            'channel_order_no' => 'CH-AUTO-001',
            'source' => 'tiktok',
            'channel_shop_id' => 'shop_123',
            'customer_name' => 'John Doe',
            'transaction_date' => now(),
            'sub_total' => 60000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 60000,
            'channel_status' => 'SHIPPED',
            'channel_status_raw' => 'SHIPPED',
            'is_paid' => true,
            'is_canceled' => false,
            'payment_method' => 'ONLINE',
            'location_id' => $this->kecilLocationId,
            'is_shadow' => false,
            'items' => [
                [
                    'item_id' => $this->itemId,
                    'sku' => $this->sku,
                    'qty_in_base' => 4,
                    'qty' => 4,
                    'unit_price' => 15000,
                ],
            ],
        ];

        $orderId = app(\Modules\Sales\Services\SalesOrderService::class)->upsertFromChannel($orderPayload);
        $this->assertNotNull($orderId);

        $this->assertEquals(16, (int) DB::table('inventories')->where('item_id', $this->itemId)->where('bin_id', $this->binId)->value('on_hand'));

        $movement = DB::table('inventory_movements')
            ->where('transaction_number', 'SO-AUTO-001')
            ->where('source', 'ORDER_COMPLETE_OUT')
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(-4, (int) $movement->qty);
        $this->assertEquals(16, (int) $movement->balance);
        $this->assertEquals('ORDER_COMPLETE_OUT', $movement->source);
        $this->assertEquals('system:backfill', $movement->created_by);

        $this->assertTrue(DB::table('order_bin_allocations')->where('order_id', $orderId)->exists());
    }

    public function test_transition_from_shipped_to_completed_does_not_double_deduct(): void
    {
        config()->set('inventory.channel_auto_physical_backfill', true);

        $shopId = Str::uuid()->toString();
        $channelId = DB::table('channels')->where('code', 'tiktok')->value('id');

        DB::table('channel_shops')->insert([
            'id' => $shopId,
            'channel_id' => $channelId,
            'shop_id' => 'shop_tiktok_01',
            'shop_name' => 'TikTok Shop Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pcmId = Str::uuid()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcmId,
            'product_id' => $this->productId,
            'channel_shop_id' => $shopId,
            'external_product_id' => 'EXT-TIKTOK-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_variant_channel_mappings')->insert([
            'id' => Str::uuid()->toString(),
            'product_channel_mapping_id' => $pcmId,
            'variant_id' => $this->itemId,
            'external_sku_id' => 'EXT-SKU-TIKTOK-01',
            'channel_seller_sku' => $this->sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->kecilLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 50,
            'available' => 50,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(\Modules\Sales\Services\SalesOrderService::class);

        $shippedPayload = [
            'salesorder_no' => 'SO-TIKTOK-DOUBLE-CHECK',
            'channel_order_no' => 'CH-TIKTOK-DOUBLE-CHECK',
            'source' => 'tiktok',
            'channel_shop_id' => 'shop_tiktok_01',
            'customer_name' => 'Jane Doe',
            'transaction_date' => now(),
            'sub_total' => 30000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 30000,
            'channel_status' => 'SHIPPED',
            'channel_status_raw' => 'SHIPPED',
            'is_paid' => true,
            'is_canceled' => false,
            'payment_method' => 'ONLINE',
            'location_id' => $this->kecilLocationId,
            'is_shadow' => false,
            'items' => [
                [
                    'item_id' => $this->itemId,
                    'sku' => $this->sku,
                    'qty_in_base' => 2,
                    'qty' => 2,
                    'unit_price' => 15000,
                ],
            ],
        ];

        $orderId = $service->upsertFromChannel($shippedPayload);
        $this->assertNotNull($orderId);

        $this->assertEquals(48, (int) DB::table('inventories')->where('item_id', $this->itemId)->where('bin_id', $this->binId)->value('on_hand'));
        $this->assertEquals(1, DB::table('inventory_movements')->where('transaction_number', 'SO-TIKTOK-DOUBLE-CHECK')->where('source', 'ORDER_COMPLETE_OUT')->count());

        $completedPayload = $shippedPayload;
        $completedPayload['channel_status'] = 'COMPLETED';
        $completedPayload['channel_status_raw'] = 'COMPLETED';

        $service->upsertFromChannel($completedPayload);

        $this->assertEquals(48, (int) DB::table('inventories')->where('item_id', $this->itemId)->where('bin_id', $this->binId)->value('on_hand'));

        $this->assertEquals(1, DB::table('inventory_movements')->where('transaction_number', 'SO-TIKTOK-DOUBLE-CHECK')->where('source', 'ORDER_COMPLETE_OUT')->count());
    }
}
