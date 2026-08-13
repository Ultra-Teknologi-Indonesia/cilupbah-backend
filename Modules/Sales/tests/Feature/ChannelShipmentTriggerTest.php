<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Outbound\Jobs\ProcessPacklistCompleteJob;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Services\SalesOrderService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ChannelShipmentTriggerTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $locationId;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        $this->userId = $this->seedUser();
        $this->locationId = $this->seedLocation(Location::SYSTEM_KECIL_CODE);
        $this->variantId = $this->seedProductVariant('SKU-CST-1');
        $this->seedStock(10);
    }

    private function seedStock(int $qty): void
    {
        $binId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $binId,
            'location_id' => $this->locationId,
            'bin_final_code' => 'GK-CST-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => $binId,
            'on_hand' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_menyerahkan_ke_gudang_tidak_meminta_resi_ke_marketplace(): void
    {
        $order = $this->seedOrder('shopee');

        app(SalesOrderService::class)->moveToReadyToProcess([$order]);

        Queue::assertNotPushed(RequestChannelAwbJob::class);

        $this->assertNotNull(DB::table('sales_orders')->where('id', $order)->value('handed_to_warehouse_at'));
        $this->assertSame('reserved', DB::table('sales_orders')->where('id', $order)->value('status'));
    }

    public function test_selesai_packing_meminta_resi_ke_marketplace(): void
    {
        $order = $this->seedOrder('shopee', status: 'picked');
        $packlist = $this->seedCompletedPacklist($order);

        app(ProcessPacklistCompleteJob::class, ['packlistId' => $packlist])
            ->handle(app(SalesOrderService::class));

        Queue::assertPushed(RequestChannelAwbJob::class);
        $this->assertSame('packed', DB::table('sales_orders')->where('id', $order)->value('status'));
    }

    public function test_pesanan_kurir_instan_juga_meminta_resi_di_selesai_packing(): void
    {
        $order = $this->seedOrder('shopee', status: 'picked', shippingProvider: 'GrabExpress', shippingType: 'Instant');
        $packlist = $this->seedCompletedPacklist($order);

        app(ProcessPacklistCompleteJob::class, ['packlistId' => $packlist])
            ->handle(app(SalesOrderService::class));

        Queue::assertPushed(RequestChannelAwbJob::class);

        $this->assertSame('packed', DB::table('sales_orders')->where('id', $order)->value('status'));
    }

    public function test_pesanan_same_day_juga_meminta_resi_di_selesai_packing(): void
    {
        $order = $this->seedOrder('tiktok', status: 'picked', shippingProvider: 'GoSend', shippingType: 'Same Day');
        $packlist = $this->seedCompletedPacklist($order);

        app(ProcessPacklistCompleteJob::class, ['packlistId' => $packlist])
            ->handle(app(SalesOrderService::class));

        Queue::assertPushed(RequestChannelAwbJob::class);
    }

    public function test_pesanan_non_marketplace_tidak_meminta_resi(): void
    {
        $order = $this->seedOrder('manual', status: 'picked');
        $packlist = $this->seedCompletedPacklist($order);

        app(ProcessPacklistCompleteJob::class, ['packlistId' => $packlist])
            ->handle(app(SalesOrderService::class));

        Queue::assertNotPushed(RequestChannelAwbJob::class);
    }

    public function test_manifest_tidak_mengatur_ulang_pengiriman_yang_sudah_diatur(): void
    {
        $order = $this->seedOrder('shopee', status: 'packed');
        DB::table('sales_orders')->where('id', $order)->update(['channel_status' => 'PROCESSED']);

        $results = app(OutboundFulfillmentService::class)->readyToShip([$order]);

        $this->assertSame('skipped', $results[0]['status']);
        Http::assertNothingSent();
    }

    public function test_manifest_tetap_jadi_jaring_pengaman_kalau_packing_gagal_atur_kirim(): void
    {
        $order = $this->seedOrder('shopee', status: 'packed');
        DB::table('sales_orders')->where('id', $order)->update(['channel_status' => 'READY_TO_SHIP']);

        $results = app(OutboundFulfillmentService::class)->readyToShip([$order]);

        $this->assertNotSame('skipped', $results[0]['status']);
    }

    public function test_endpoint_manual_tarik_resi_tetap_dijaga_sumbu_fulfillment(): void
    {
        $shopId = $this->seedShadowShop();
        $order = $this->seedOrder('shopee', status: 'picked');
        DB::table('sales_orders')->where('id', $order)->update(['channel_shop_id' => $shopId]);

        $results = app(OutboundFulfillmentService::class)->readyToShip([$order]);

        $this->assertSame(
            'skipped',
            $results[0]['status'],
            'Endpoint request-awb memanggil readyToShip langsung tanpa lewat job, jadi guard wajib ada di service.',
        );
        Http::assertNothingSent();
    }

    private function seedShadowShop(): string
    {
        $channelId = DB::table('channels')->where('code', 'shopee')->value('id');

        if (! $channelId) {
            $channelId = Str::uuid()->toString();
            DB::table('channels')->insert([
                'id' => $channelId,
                'code' => 'shopee',
                'name' => 'Shopee',
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('channel_shops')->insert([
            'id' => Str::uuid()->toString(),
            'channel_id' => $channelId,
            'shop_id' => '990011',
            'shop_name' => 'Toko Hening',
            'is_active' => true,
            'fulfillment_push_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return '990011';
    }

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Petugas',
            'email' => 'cst+'.substr($id, 0, 6).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedLocation(string $code): string
    {
        $existing = DB::table('locations')->where('location_code', $code)->value('id');

        if ($existing) {
            return (string) $existing;
        }

        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => 'Gudang Kecil',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedProductVariant(string $sku): string
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Produk '.$sku,
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $variantId;
    }

    private function seedOrder(
        string $source,
        string $status = 'reserved',
        string $shippingProvider = 'J&T Express',
        string $shippingType = 'Standard',
    ): string {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'CST-SO-'.substr($orderId, 0, 6),
            'customer_name' => 'Pembeli',
            'location_id' => $this->locationId,
            'source' => $source,
            'channel_shop_id' => $source === 'manual' ? null : '778899',
            'channel_order_no' => $source === 'manual' ? null : 'CH-'.substr($orderId, 0, 6),
            'shipping_provider' => $shippingProvider,
            'shipping_type' => $shippingType,
            'status' => $status,
            'transaction_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sales_order_items')->insert([
            'id' => Str::uuid()->toString(),
            'order_id' => $orderId,
            'item_id' => $this->variantId,
            'sku' => 'SKU-CST-1',
            'qty_in_base' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function seedCompletedPacklist(string $orderId): string
    {
        $packlistId = Str::uuid()->toString();
        DB::table('packlists')->insert([
            'id' => $packlistId,
            'packlist_no' => 'CST-PACK-'.substr($packlistId, 0, 6),
            'location_id' => $this->locationId,
            'order_id' => $orderId,
            'packer_id' => $this->userId,
            'status' => Packlist::STATUS_COMPLETED,
            'created_by' => $this->userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $packlistId;
    }
}
