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

        // Order channel SELALU dialokasikan ke WH-KECIL (SalesOrderService::resolveLocationId),
        // jadi fixture harus memakai lokasi itu -- kalau bikin lokasi sendiri, alokasinya
        // mendarat di baris lain dan assertion di sini tidak melihat perubahan apa pun.
        $kecilCode = \Modules\Warehouse\Models\Location::SYSTEM_KECIL_CODE;
        $existing = DB::table('locations')->where('location_code', $kecilCode)->value('id');

        if ($existing) {
            $this->locationId = $existing;
        } else {
            $this->locationId = Str::uuid()->toString();
            DB::table('locations')->insert([
                'id' => $this->locationId,
                'location_code' => $kecilCode,
                'location_name' => 'Gudang Kecil',
                'location_type' => 'WAREHOUSE',
                'is_warehouse' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
        $this->assertSame(0, $inv->on_order, 'alokasi harus dilepas saat melewati packed');
        $this->assertSame(
            10,
            $inv->on_hand,
            'on_hand TIDAK turun di jalur channel: sejak 647876d1, pemotongan fisik hanya '
            . 'terjadi saat picker men-scan rak (PicklistService::pickItem)'
        );

        $this->service->upsertFromChannel($this->orderData('LZ-RC-1', 'COMPLETED'));

        $inv = $this->inventory();
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(10, $inv->on_hand, 'shipped bukan gerakan stok');

        $this->assertSame(1, $this->movements('ORDER_RESERVE'), 'alokasi tepat sekali');
        $this->assertSame(1, $this->movements('ORDER_RELEASE'), 'pelepasan tepat sekali');
        $this->assertSame(0, $this->movements('ORDER_PICK'), 'ORDER_PICK sudah tidak ditulis sejak 647876d1');
        $this->assertSame(0, $this->movements('ORDER_SHIP'), 'ORDER_SHIP sudah tidak ditulis: pengiriman bukan gerakan stok');
    }

    public function test_pending_then_packed_reserves_and_releases_once(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-2', 'UNPAID'));
        $this->assertSame(10, $this->inventory()->on_hand);
        $this->assertSame(
            2,
            $this->inventory()->on_order,
            'order channel dialokasikan begitu masuk, tanpa menunggu pembayaran'
        );

        $this->service->upsertFromChannel($this->orderData('LZ-RC-2', 'AWAITING_COLLECTION'));

        $inv = $this->inventory();
        $this->assertSame(10, $inv->on_hand, 'fisik baru berkurang saat picking');
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(1, $this->movements('ORDER_RESERVE'));
        $this->assertSame(1, $this->movements('ORDER_RELEASE'));
    }

    public function test_channel_reservation_allows_oversell(): void
    {
        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->update(['on_hand' => 1, 'on_order' => 0, 'available' => 1]);

        $this->service->upsertFromChannel($this->orderData('LZ-RC-3', 'AWAITING_SHIPMENT'));

        $inv = $this->inventory();
        $this->assertSame(2, $inv->on_order, 'order channel tetap di-reserve walau stok kurang');

        // Kolom `available` pada baris agregat (bin_id = NULL) selalu 0 --
        // recalculateAvailable() memaksanya begitu untuk baris non-placed.
        // Ketersediaan sebenarnya dihitung on-the-fly: on_hand - on_order.
        // Lihat PLANNING-KOLOM-AVAILABLE-SEMANTIK.md; jangan "perbaiki" di sini.
        $this->assertSame(
            -1,
            (int) $inv->on_hand - (int) $inv->on_order,
            'available efektif boleh minus untuk order yang sudah committed di marketplace'
        );
    }
}
