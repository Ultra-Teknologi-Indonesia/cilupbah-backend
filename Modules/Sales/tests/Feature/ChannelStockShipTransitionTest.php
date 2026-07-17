<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class ChannelStockShipTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $service;
    protected string $variantId;
    protected string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesOrderService::class);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Stok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Stok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-SHIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-SHIP',
            'location_name' => 'Gudang Ship',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function orderData(string $orderNo, string $channelStatus): array
    {
        return [
            'salesorder_no' => $orderNo,
            'channel_shop_id' => 'SHOP-1',
            'customer_name' => 'Buyer Stok',
            'transaction_date' => now(),
            'sub_total' => 10000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 10000,
            'shipping_full_name' => null,
            'shipping_phone' => null,
            'shipping_address' => null,
            'shipping_city' => null,
            'shipping_province' => null,
            'shipping_post_code' => null,
            'shipping_country' => null,
            'channel_status' => $channelStatus,
            'status' => 'pending',
            'is_paid' => $channelStatus !== 'UNPAID',
            'payment_method' => null,
            'source' => 'lazada',
            'items' => [[
                'channel_product_id' => 'CP-1',
                'sku' => 'SKU-SHIP',
                'description' => 'Item Ship',
                'qty_in_base' => 2,
                'price' => 5000,
                'disc' => 0,
                'disc_amount' => 0,
                'tax_amount' => 0,
                'amount' => 10000,
            ]],
        ];
    }

    protected function inventory(): object
    {
        return DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->first();
    }

    protected function movements(string $source): int
    {
        return DB::table('inventory_movements')
            ->where('item_id', $this->variantId)
            ->where('source', $source)
            ->count();
    }

    public function test_reserved_to_shipped_releases_reserved_and_decrements_on_hand(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-1', 'AWAITING_SHIPMENT'));

        $inv = $this->inventory();
        $this->assertSame(2, $inv->on_order);
        $this->assertSame(10, $inv->on_hand);

        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-1', 'DELIVERED'));

        $inv = $this->inventory();
        $this->assertSame(0, $inv->on_order, 'reserved harus dilepas saat shipped tanpa picklist WMS');
        $this->assertSame(8, $inv->on_hand, 'on_hand harus berkurang sesuai qty order');
        $this->assertSame(8, $inv->available);
        $this->assertSame(1, $this->movements('ORDER_PICK'));
        $this->assertSame(1, $this->movements('ORDER_SHIP'));

        $this->assertDatabaseHas('sales_orders', [
            'salesorder_no' => 'LZ-SHIP-1',
            'status' => 'shipped',
        ]);
    }

    public function test_packed_to_shipped_does_not_double_decrement(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-2', 'AWAITING_SHIPMENT'));

        DB::table('sales_orders')->where('salesorder_no', 'LZ-SHIP-2')->update(['status' => 'packed']);
        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->update(['on_hand' => 8, 'on_order' => 0, 'available' => 8]);

        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-2', 'DELIVERED'));

        $inv = $this->inventory();
        $this->assertSame(8, $inv->on_hand, 'on_hand tidak boleh dikurangi dua kali');
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(0, $this->movements('ORDER_PICK'), 'tidak boleh ada PICK kedua dari jalur channel');
        $this->assertSame(1, $this->movements('ORDER_SHIP'));
    }

    public function test_first_pull_already_delivered_leaves_stock_untouched(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-3', 'DELIVERED'));

        $inv = $this->inventory();
        $this->assertSame(10, $inv->on_hand);
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(0, $this->movements('ORDER_PICK'));
        $this->assertSame(0, $this->movements('ORDER_SHIP'));
    }

    public function test_reserved_to_cancelled_still_releases_reserved(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-4', 'AWAITING_SHIPMENT'));
        $this->assertSame(2, $this->inventory()->on_order);

        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-4', 'CANCELLED'));

        $inv = $this->inventory();
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(10, $inv->on_hand);
        $this->assertSame(1, $this->movements('ORDER_CANCEL'));
    }

    public function test_shipped_order_is_not_reprocessed_on_repeat_webhook(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-5', 'AWAITING_SHIPMENT'));
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-5', 'DELIVERED'));
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-5', 'COMPLETED'));

        $inv = $this->inventory();
        $this->assertSame(8, $inv->on_hand, 'webhook shipped berulang tidak boleh mengurangi stok lagi');
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(1, $this->movements('ORDER_PICK'));
        $this->assertSame(1, $this->movements('ORDER_SHIP'));
    }
}
