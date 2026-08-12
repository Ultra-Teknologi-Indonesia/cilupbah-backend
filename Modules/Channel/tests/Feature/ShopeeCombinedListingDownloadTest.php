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

    public function test_satu_listing_menautkan_dua_master_sekaligus(): void
    {
        $shop = $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $this->assertNotNull($this->pcmFor($this->transparant, $shop), 'Master transparant harus punya mapping ke listing ini');
        $this->assertNotNull($this->pcmFor($this->strongMagnet, $shop), 'Master strong-magnet harus punya mapping ke listing yang sama');
    }

    public function test_sku_yatim_dibuat_di_master_sesuai_variasi_bukan_prefix(): void
    {
        $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $baru = DB::table('product_variants')->where('sku', 'MAGSAFE-CLEAR-IP-14')->first();
        $this->assertNotNull($baru, 'SKU yatim harus dibuatkan varian, bukan dibuang');
        $this->assertEquals($this->transparant->id, $baru->product_id, 'Varian Normal Magnet harus masuk master transparant');

        $baruStrong = DB::table('product_variants')->where('sku', 'STRONG-MAGNET-IP-17')->first();
        $this->assertNotNull($baruStrong);
        $this->assertEquals($this->strongMagnet->id, $baruStrong->product_id, 'Varian Strong Magnet harus masuk master strong-magnet');

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
        $pcm = $this->pcmFor($this->strongMagnet, $shop);

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

    public function test_semua_model_ber_sku_tertaut(): void
    {
        $shop = $this->makeShop();
        $this->fakeShopee();

        app(ShopeeProductService::class)->pullProducts('8434311');

        $total = $this->linkCount($this->transparant, $shop) + $this->linkCount($this->strongMagnet, $shop);

        $this->assertEquals(7, $total, '7 dari 8 model punya SKU dan semuanya harus tertaut');
        $this->assertEquals(3, $this->linkCount($this->transparant, $shop));
        $this->assertEquals(4, $this->linkCount($this->strongMagnet, $shop));
    }
}
