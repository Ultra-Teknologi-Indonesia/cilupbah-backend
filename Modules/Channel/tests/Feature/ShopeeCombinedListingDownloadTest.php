<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class ShopeeCombinedListingDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const EXTERNAL_ID = '8729043644';

    private Product $transparant;
    private Product $strongMagnet;
    private Attribute $variasi;
    private Attribute $tipeHp;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $category = Category::create(['name' => 'Casing', 'is_active' => true]);

        $this->variasi = Attribute::firstOrCreate(['name' => 'Variasi'], ['type' => 'sales']);
        $this->tipeHp = Attribute::firstOrCreate(['name' => 'Type HP'], ['type' => 'sales']);

        $this->transparant = $this->makeMaster($category->id, 'Soft Case Magsafe Transparant', 'Normal Magnet', [
            'MAGSAFE-CLEAR-IP-11' => '11',
            'MAGSAFE-CLEAR-IP-13' => '13',
        ]);

        $this->strongMagnet = $this->makeMaster($category->id, 'CILUPBAH Clear Magnetic Strong Magsafe', 'Strong Magnet', [
            'STRONG-MAGNET-IP-11' => '11',
            'STRONG-MAGNET-IP-13' => '13',
        ]);
    }

    private function makeMaster(int|string $categoryId, string $name, string $variasi, array $skuToTipe): Product
    {
        $product = Product::create([
            'name' => $name,
            'category_id' => $categoryId,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'weight' => 0.05,
        ]);

        foreach ([$this->variasi->id => 0, $this->tipeHp->id => 1] as $attributeId => $sort) {
            DB::table('product_variation_types')->insert([
                'product_id' => $product->id,
                'attribute_id' => $attributeId,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($skuToTipe as $sku => $tipe) {
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $sku,
                'sell_price' => 100000,
                'is_active' => true,
            ]);

            DB::table('variant_options')->insert([
                ['variant_id' => $variant->id, 'attribute_id' => $this->variasi->id, 'value' => $variasi, 'created_at' => now(), 'updated_at' => now()],
                ['variant_id' => $variant->id, 'attribute_id' => $this->tipeHp->id, 'value' => $tipe, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        return $product;
    }

    private function makeShop(): ChannelShop
    {
        $channel = Channel::firstOrCreate(['code' => 'shopee'], ['name' => 'Shopee', 'is_active' => true]);

        return ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '8434311',
            'shop_name' => 'Cilupbah Case Official Shop',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
            'is_active' => true,
        ]);
    }

    private function fakeShopee(): void
    {
        $models = [
            ['model_id' => 208100350033, 'model_sku' => 'STRONG-MAGNET-IP-17', 'tier_index' => [1, 3]],
            ['model_id' => 117764526177, 'model_sku' => 'MAGSAFE-CLEAR-IP-14', 'tier_index' => [0, 2]],
            ['model_id' => 208100350029, 'model_sku' => 'STRONG-MAGNET-IP-13', 'tier_index' => [1, 2]],
            ['model_id' => 131192420820, 'model_sku' => 'MAGSAFE-CLEAR-IP-13', 'tier_index' => [0, 1]],
            ['model_id' => 415868124923, 'model_sku' => '', 'tier_index' => [0, 3]],
            ['model_id' => 208100350019, 'model_sku' => 'STRONG-MAGNET-IP-11', 'tier_index' => [1, 0]],
            ['model_id' => 208100350025, 'model_sku' => 'STRONG-MAGNET-IP-13', 'tier_index' => [1, 1]],
            ['model_id' => 341589863978, 'model_sku' => 'MAGSAFE-CLEAR-IP-11', 'tier_index' => [0, 0]],
        ];

        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_item_list*' => Http::response([
                'response' => [
                    'item' => [['item_id' => (int) self::EXTERNAL_ID, 'item_status' => 'NORMAL']],
                    'total_count' => 1,
                    'has_next_page' => false,
                    'next_offset' => 1,
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/product/get_item_base_info*' => Http::response([
                'response' => [
                    'item_list' => [[
                        'item_id' => (int) self::EXTERNAL_ID,
                        'item_name' => 'Magsafe Wireless Charging Case',
                        'item_status' => 'NORMAL',
                        'has_model' => true,
                        'price_info' => [['current_price' => 100000]],
                    ]],
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/product/get_model_list*' => Http::response([
                'response' => [
                    'tier_variation' => [
                        ['name' => 'Variasi', 'option_list' => [['option' => 'Normal Magnet'], ['option' => 'Strong Magnet']]],
                        ['name' => 'Type HP', 'option_list' => [['option' => '11'], ['option' => '13'], ['option' => '14'], ['option' => '17']]],
                    ],
                    'model' => array_map(
                        fn ($m) => $m + ['price_info' => [['current_price' => 100000]]],
                        $models
                    ),
                ],
            ], 200),
        ]);
    }

    private function pcmFor(Product $product, ChannelShop $shop): ?object
    {
        return DB::table('product_channel_mappings')
            ->where('product_id', $product->id)
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', self::EXTERNAL_ID)
            ->first();
    }

    private function linkCount(Product $product, ChannelShop $shop): int
    {
        $pcm = $this->pcmFor($product, $shop);

        return $pcm
            ? DB::table('product_variant_channel_mappings')->where('product_channel_mapping_id', $pcm->id)->count()
            : 0;
    }

    private function satuSatunyaPcm(ChannelShop $shop): object
    {
        $rows = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', '8729043644')
            ->get();

        $this->assertCount(1, $rows, 'Satu listing hanya boleh mendarat di satu master');

        return $rows->first();
    }

    public function test_satu_listing_dikonsolidasikan_ke_satu_master(): void
    {
        $shop = $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $pcm = $this->satuSatunyaPcm($shop);

        $this->assertContains(
            $pcm->product_id,
            [$this->transparant->id, $this->strongMagnet->id],
            'Master pemenang harus salah satu dari dua master awal'
        );
    }

    public function test_semua_sku_listing_mendarat_di_master_yang_sama(): void
    {
        $shop = $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $pemenang = $this->satuSatunyaPcm($shop)->product_id;

        foreach (['MAGSAFE-CLEAR-IP-14', 'STRONG-MAGNET-IP-17', 'MAGSAFE-CLEAR-IP-11', 'STRONG-MAGNET-IP-13'] as $sku) {
            $variant = DB::table('product_variants')->where('sku', $sku)->first();
            $this->assertNotNull($variant, "SKU {$sku} harus ada di master");
            $this->assertEquals($pemenang, $variant->product_id, "SKU {$sku} harus berada di master pemenang");
        }

        $baru = DB::table('product_variants')->where('sku', 'MAGSAFE-CLEAR-IP-14')->first();
        $opsi = DB::table('variant_options')->where('variant_id', $baru->id)->pluck('value', 'attribute_id');
        $this->assertEquals('Normal Magnet', $opsi[$this->variasi->id] ?? null);
        $this->assertEquals('14', $opsi[$this->tipeHp->id] ?? null);
    }

    public function test_dua_model_ber_sku_sama_dapat_baris_link_sendiri_sendiri(): void
    {
        $shop = $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $variant = DB::table('product_variants')->where('sku', 'STRONG-MAGNET-IP-13')->first();
        $pcm = $this->satuSatunyaPcm($shop);

        $links = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->where('variant_id', $variant->id)
            ->pluck('external_sku_id')
            ->sort()
            ->values()
            ->all();

        $this->assertEquals(['208100350025', '208100350029'], $links, 'Kedua model Shopee harus punya baris link sendiri, tidak saling menimpa');
    }

    public function test_model_tanpa_sku_dilewati_tapi_dicatat(): void
    {
        $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $log = DB::table('product_sync_logs')
            ->where('action', 'download')
            ->where('status', 'failed')
            ->get()
            ->first(fn ($row) => str_contains((string) $row->payload, '415868124923'));

        $this->assertNotNull($log, 'Model tanpa SKU harus tercatat di product_sync_logs, bukan dibuang senyap');
        $this->assertStringContainsString('SKU', (string) $log->error_message);
    }

    public function test_setelah_digabung_satu_master_memuat_semua_sku_listing(): void
    {
        $shop = $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $this->artisan('products:merge-masters', [
            'target' => $this->transparant->id,
            'source' => [$this->strongMagnet->id],
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('products', ['id' => $this->strongMagnet->id]);

        $skus = DB::table('product_variants')
            ->where('product_id', $this->transparant->id)
            ->whereNull('deleted_at')
            ->pluck('sku')
            ->sort()
            ->values()
            ->all();

        $this->assertEquals([
            'MAGSAFE-CLEAR-IP-11',
            'MAGSAFE-CLEAR-IP-13',
            'MAGSAFE-CLEAR-IP-14',
            'STRONG-MAGNET-IP-11',
            'STRONG-MAGNET-IP-13',
            'STRONG-MAGNET-IP-17',
        ], $skus, 'Semua SKU listing harus berkumpul di satu master');

        $pcms = DB::table('product_channel_mappings')
            ->where('product_id', $this->transparant->id)
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', self::EXTERNAL_ID)
            ->pluck('id');

        $this->assertCount(1, $pcms, 'Dua mapping ke listing yang sama harus dilebur jadi satu');

        $links = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcms->first())
            ->count();

        $this->assertEquals(7, $links, 'Seluruh model ber-SKU tetap tertaut setelah penggabungan');
    }

    public function test_gabung_ditolak_kalau_ada_sku_bentrok(): void
    {
        DB::table('product_variants')
            ->where('sku', 'STRONG-MAGNET-IP-11')
            ->update(['sku' => 'MAGSAFE-CLEAR-IP-11-DUP']);

        ProductVariant::create([
            'product_id' => $this->transparant->id,
            'sku' => 'MAGSAFE-CLEAR-IP-11-DUP2',
            'sell_price' => 1000,
            'is_active' => true,
        ]);

        $this->artisan('products:merge-masters', [
            'target' => $this->transparant->id,
            'source' => [$this->transparant->id],
        ])->assertFailed();
    }

    public function test_semua_model_ber_sku_tertaut(): void
    {
        $shop = $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $pcm = $this->satuSatunyaPcm($shop);

        $total = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->count();

        $this->assertEquals(7, $total, '7 dari 8 model punya SKU dan semuanya harus tertaut di satu master');
    }
}
