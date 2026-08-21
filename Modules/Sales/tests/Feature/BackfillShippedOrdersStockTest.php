<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class BackfillShippedOrdersStockTest extends TestCase
{
    use RefreshDatabase;

    private string $kecilLocationId;
    private string $binId;
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

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Test Product',
            'category_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $productId,
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

    public function test_allows_negative_stock_when_on_hand_is_zero(): void
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

        $this->assertEquals(-5, (int) DB::table('inventories')->where('item_id', $this->itemId)->value('on_hand'));

        $movement = DB::table('inventory_movements')->where('transaction_number', 'SO-TEST-003')->first();
        $this->assertNotNull($movement);
        $this->assertEquals(-5, (int) $movement->qty);
        $this->assertEquals(-5, (int) $movement->balance);
    }
}
