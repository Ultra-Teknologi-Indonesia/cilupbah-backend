<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class ChannelStockReconcileTest extends TestCase
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
            'name' => 'Kategori Reconcile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Reconcile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-RECON',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-RECON',
            'location_name' => 'Gudang Reconcile',
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
            'customer_name' => 'Buyer Reconcile',
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
            'is_paid' => true,
            'payment_method' => null,
            'source' => 'lazada',
            'items' => [[
                'channel_product_id' => 'CP-1',
                'sku' => 'SKU-RECON',
                'description' => 'Item Reconcile',
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

    public function test_reserved_then_packed_then_shipped_decrements_once(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-1', 'AWAITING_SHIPMENT'));
        $this->assertSame(2, $this->inventory()->on_order);
        $this->assertSame(10, $this->inventory()->on_hand);

        $this->service->upsertFromChannel($this->orderData('LZ-RC-1', 'AWAITING_COLLECTION'));

        $inv = $this->inventory();
        $this->assertSame(0, $inv->on_order, 'reserved harus dilepas saat melewati packed');
        $this->assertSame(8, $inv->on_hand, 'on_hand harus berkurang saat pick terjadi di langkah reserved->packed');

        $this->service->upsertFromChannel($this->orderData('LZ-RC-1', 'COMPLETED'));

        $inv = $this->inventory();
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(8, $inv->on_hand, 'on_hand tidak boleh berkurang lagi saat shipped');
        $this->assertSame(8, $inv->available);
        $this->assertSame(1, $this->movements('ORDER_PICK'), 'pick tepat sekali');
        $this->assertSame(1, $this->movements('ORDER_SHIP'), 'ship tepat sekali');
    }

    public function test_pending_then_packed_reserves_and_picks_once(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-2', 'UNPAID'));
        $this->assertSame(10, $this->inventory()->on_hand);
        $this->assertSame(0, $this->inventory()->on_order);

        $this->service->upsertFromChannel($this->orderData('LZ-RC-2', 'AWAITING_COLLECTION'));

        $inv = $this->inventory();
        $this->assertSame(8, $inv->on_hand);
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(1, $this->movements('ORDER_RESERVE'));
        $this->assertSame(1, $this->movements('ORDER_PICK'));
    }

    public function test_channel_reservation_allows_oversell(): void
    {
        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->update(['on_hand' => 1, 'on_order' => 0, 'available' => 1]);

        $this->service->upsertFromChannel($this->orderData('LZ-RC-3', 'AWAITING_SHIPMENT'));

        $inv = $this->inventory();
        $this->assertSame(2, $inv->on_order, 'order channel tetap di-reserve walau stok kurang');
        $this->assertSame(-1, $inv->available, 'available boleh minus untuk order yang sudah committed di marketplace');
    }
}
