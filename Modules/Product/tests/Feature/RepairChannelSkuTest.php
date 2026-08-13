<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class RepairChannelSkuTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $channel = Channel::firstOrCreate(['code' => 'lazada'], ['name' => 'Lazada', 'is_active' => true]);

        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'LZ-REPAIR',
            'shop_name' => 'Toko Repair',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
            'is_active' => true,
        ]);
    }

    private function master(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Master Uji',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_from_channel' => true,
        ], $attributes));
    }

    private function varian(Product $product, ?string $sku): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'sell_price' => 10000,
            'is_active' => true,
        ]);
    }

    private function tautkan(Product $product, ProductVariant $variant, string $externalProductId): void
    {
        $pcm = ProductChannelMapping::firstOrCreate([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => $externalProductId,
        ], ['sync_status' => 'synced']);

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $pcm->id,
            'variant_id' => $variant->id,
            'channel_sku' => $variant->sku,
        ]);
    }

    public function test_sku_master_yang_menyalin_sku_varian_dikosongkan(): void
    {
        $master = $this->master(['sku' => 'MAGSAFE-CLEAR-IP-11']);
        $this->varian($master, 'MAGSAFE-CLEAR-IP-11');

        $this->artisan('products:repair-channel-sku --apply --only=master')->assertSuccessful();

        $this->assertNull($master->fresh()->sku);
        $this->assertNotNull(
            ProductVariant::where('sku', 'MAGSAFE-CLEAR-IP-11')->first(),
            'SKU varian-nya sendiri tidak boleh ikut hilang'
        );
    }

    public function test_sku_master_dikosongkan_walau_variannya_sudah_pindah_master(): void
    {
        $lama = $this->master(['sku' => 'PINDAH-1']);
        $baru = $this->master(['name' => 'Master Pemenang']);
        $this->varian($baru, 'PINDAH-1');

        $this->artisan('products:repair-channel-sku --apply --only=master')->assertSuccessful();

        $this->assertNull($lama->fresh()->sku);
    }

    public function test_sku_master_asli_dari_seller_center_tidak_disentuh(): void
    {
        $master = $this->master(['sku' => 'INDUK-ASLI']);
        $this->varian($master, 'VARIAN-LAIN');

        $this->artisan('products:repair-channel-sku --apply --only=master')->assertSuccessful();

        $this->assertSame('INDUK-ASLI', $master->fresh()->sku);
    }

    public function test_master_non_channel_tidak_disentuh(): void
    {
        $master = $this->master(['sku' => 'MANUAL-1', 'is_from_channel' => false]);
        $this->varian($master, 'MANUAL-1');

        $this->artisan('products:repair-channel-sku --apply --only=master')->assertSuccessful();

        $this->assertSame('MANUAL-1', $master->fresh()->sku);
    }

    public function test_pratinjau_tidak_mengubah_apa_pun(): void
    {
        $master = $this->master(['sku' => 'PRATINJAU-1']);
        $palsu = $this->varian($master, 'PRATINJAU-1');
        $this->tautkan($master, $palsu, '8357466458');

        $auto = $this->varian($master, '8357466458-1786526430066-56');
        $this->tautkan($master, $auto, '8357466458');

        $this->artisan('products:repair-channel-sku')->assertSuccessful();

        $this->assertSame('PRATINJAU-1', $master->fresh()->sku);
        $this->assertNull($auto->fresh()->deleted_at);
        $this->assertSame(2, ProductVariantChannelMapping::count());
    }

    public function test_varian_ber_sku_bikinan_marketplace_dihapus_dan_dilepas(): void
    {
        $master = $this->master();
        $asli = $this->varian($master, 'MAGSAFE-CLEAR-IP-11');
        $auto = $this->varian($master, '8357466458-1786526430066-56');
        $strip = $this->varian($master, '-');

        $this->tautkan($master, $asli, '8357466458');
        $this->tautkan($master, $auto, '8357466458');
        $this->tautkan($master, $strip, '8357466458');

        $this->artisan('products:repair-channel-sku --apply --only=varian')->assertSuccessful();

        $this->assertNotNull($auto->fresh()->deleted_at);
        $this->assertNotNull($strip->fresh()->deleted_at);
        $this->assertNull($asli->fresh()->deleted_at, 'SKU asli tidak boleh ikut terhapus');

        $this->assertSame(1, ProductVariantChannelMapping::count());
        $this->assertSame(
            $asli->id,
            ProductVariantChannelMapping::first()->variant_id,
            'Hanya varian ber-SKU asli yang boleh tetap tertaut'
        );
    }

    public function test_sku_angka_yang_bukan_id_listingnya_tidak_disentuh(): void
    {
        $master = $this->master();
        $asli = $this->varian($master, 'SKU-ASLI');
        $angka = $this->varian($master, '1234567890-99-1');

        $this->tautkan($master, $asli, '8357466458');
        $this->tautkan($master, $angka, '8357466458');

        $this->artisan('products:repair-channel-sku --apply --only=varian')->assertSuccessful();

        $this->assertNull(
            $angka->fresh()->deleted_at,
            'SKU angka yang tidak diawali id listingnya sendiri adalah SKU penjual, bukan placeholder'
        );
    }

    public function test_varian_placeholder_yang_dipakai_stok_dilewati(): void
    {
        $master = $this->master();
        $asli = $this->varian($master, 'SKU-ASLI');
        $auto = $this->varian($master, '8357466458-1786526430066-56');

        $this->tautkan($master, $asli, '8357466458');
        $this->tautkan($master, $auto, '8357466458');

        $locationId = \Ramsey\Uuid\Uuid::uuid7()->toString();

        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'WH-UJI',
            'location_name' => 'Gudang Uji',
            'location_type' => 'warehouse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'item_id' => $auto->id,
            'location_id' => $locationId,
            'on_hand' => 3,
            'on_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('products:repair-channel-sku --apply --only=varian')->assertSuccessful();

        $this->assertNull($auto->fresh()->deleted_at, 'Varian bersisa stok tidak boleh dihapus otomatis');
        $this->assertSame(2, ProductVariantChannelMapping::count());
    }

    public function test_varian_placeholder_terakhir_tidak_mengosongkan_master(): void
    {
        $master = $this->master();
        $satu = $this->varian($master, '8357466458-1786526430066-56');
        $dua = $this->varian($master, '8357466458-1758611403878-46');

        $this->tautkan($master, $satu, '8357466458');
        $this->tautkan($master, $dua, '8357466458');

        $this->artisan('products:repair-channel-sku --apply --only=varian')->assertSuccessful();

        $this->assertNull($satu->fresh()->deleted_at);
        $this->assertNull($dua->fresh()->deleted_at);
        $this->assertSame(
            2,
            ProductVariant::where('product_id', $master->id)->count(),
            'Master tidak boleh ditinggalkan tanpa varian sama sekali'
        );
    }
}
