<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductRepository;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class BundleChannelListingsTest extends TestCase
{
    use RefreshDatabase;

    private Product $bundleProduct;

    private ProductVariant $compVariant1;

    private ProductVariant $compVariant2;

    private ChannelShop $shopShopee;

    private ChannelShop $shopLazada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createPrivilegedUser());

        $category = Category::create(['name' => 'Aksesoris']);

        $this->bundleProduct = Product::create([
            'name' => 'Paket Bundle Case + Standing',
            'category_id' => $category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => true,
        ]);

        $compProduct1 = Product::create([
            'name' => 'Standing Phone Holder',
            'category_id' => $category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $this->compVariant1 = ProductVariant::create([
            'product_id' => $compProduct1->id,
            'sku' => 'STANDING-IP-11',
            'sell_price' => 25000,
            'is_active' => true,
        ]);

        $compProduct2 = Product::create([
            'name' => 'Case Silicone',
            'category_id' => $category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $this->compVariant2 = ProductVariant::create([
            'product_id' => $compProduct2->id,
            'sku' => 'CASE-IP-11',
            'sell_price' => 50000,
            'is_active' => true,
        ]);

        $this->bundleProduct->bundleItems()->create([
            'component_variant_id' => $this->compVariant1->id,
            'qty' => 1,
        ]);
        $this->bundleProduct->bundleItems()->create([
            'component_variant_id' => $this->compVariant2->id,
            'qty' => 1,
        ]);

        $channelShopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee']);
        $this->shopShopee = ChannelShop::create([
            'channel_id' => $channelShopee->id,
            'shop_id' => 'SHP-1',
            'shop_name' => 'Shopee Official Store',
            'is_active' => true,
        ]);

        $channelLazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $this->shopLazada = ChannelShop::create([
            'channel_id' => $channelLazada->id,
            'shop_id' => 'LZD-1',
            'shop_name' => 'Lazada Flagship Store',
            'is_active' => true,
        ]);

        $pcmShopee = Uuid::uuid7()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcmShopee,
            'product_id' => $compProduct1->id,
            'channel_shop_id' => $this->shopShopee->id,
            'external_product_id' => 'EXT-SHP-1',
            'sync_status' => 'synced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => Uuid::uuid7()->toString(),
            'product_channel_mapping_id' => $pcmShopee,
            'variant_id' => $this->compVariant1->id,
            'external_sku_id' => 'SKU-SHP-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pcmLazada = Uuid::uuid7()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcmLazada,
            'product_id' => $compProduct2->id,
            'channel_shop_id' => $this->shopLazada->id,
            'external_product_id' => 'EXT-LZD-1',
            'sync_status' => 'synced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => Uuid::uuid7()->toString(),
            'product_channel_mapping_id' => $pcmLazada,
            'variant_id' => $this->compVariant2->id,
            'external_sku_id' => 'SKU-LZD-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_bundle_channel_listings_returns_component_listings(): void
    {
        $res = $this->getJson("/api/v1/products/{$this->bundleProduct->id}/channel-listings")
            ->assertOk();

        $res->assertJsonCount(2, 'data');

        $skus = collect($res->json('data'))->pluck('sku')->all();
        $this->assertContains('STANDING-IP-11', $skus);
        $this->assertContains('CASE-IP-11', $skus);

        $standing = collect($res->json('data'))->firstWhere('sku', 'STANDING-IP-11');
        $this->assertNotEmpty($standing['listings']);
        $this->assertSame('shopee', $standing['listings'][0]['channel_code']);
        $this->assertSame('Shopee Official Store', $standing['listings'][0]['shop_name']);
        $this->assertSame('EXT-SHP-1', $standing['listings'][0]['external_product_id']);

        $case = collect($res->json('data'))->firstWhere('sku', 'CASE-IP-11');
        $this->assertNotEmpty($case['listings']);
        $this->assertSame('lazada', $case['listings'][0]['channel_code']);
        $this->assertSame('Lazada Flagship Store', $case['listings'][0]['shop_name']);
        $this->assertSame('EXT-LZD-1', $case['listings'][0]['external_product_id']);
    }

    public function test_bundle_channel_listings_filters_by_channel(): void
    {
        $res = $this->getJson("/api/v1/products/{$this->bundleProduct->id}/channel-listings?filter[channel]=shopee")
            ->assertOk();

        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'STANDING-IP-11')
            ->assertJsonPath('data.0.listings.0.channel_code', 'shopee');
    }

    public function test_bundle_channel_listings_prefers_its_own_bundle_sku_mapping(): void
    {
        $this->bundleProduct->update(['sku' => 'BUNDLE-CASE-STANDING']);
        $technicalVariant = app(ProductRepository::class)->ensureActiveBundleVariant($this->bundleProduct);

        $mappingId = Uuid::uuid7()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $mappingId,
            'product_id' => $this->bundleProduct->id,
            'channel_shop_id' => $this->shopShopee->id,
            'external_product_id' => 'EXT-BUNDLE-1',
            'sync_status' => 'synced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => Uuid::uuid7()->toString(),
            'product_channel_mapping_id' => $mappingId,
            'variant_id' => $technicalVariant->id,
            'external_sku_id' => 'MODEL-BUNDLE-1',
            'channel_seller_sku' => 'BUNDLE-CASE-STANDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/products/{$this->bundleProduct->id}/channel-listings")
            ->assertOk();

        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'BUNDLE-CASE-STANDING')
            ->assertJsonPath('data.0.listings.0.external_product_id', 'EXT-BUNDLE-1')
            ->assertJsonPath('data.0.listings.0.channel_code', 'shopee');
    }

    public function test_bundle_without_listings_returns_empty_when_not_include_unlisted(): void
    {
        $category = Category::first();
        $emptyBundle = Product::create([
            'name' => 'Bundle Kosong',
            'category_id' => $category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => true,
        ]);

        $res = $this->getJson("/api/v1/products/{$emptyBundle->id}/channel-listings")
            ->assertOk();

        $res->assertJsonCount(0, 'data');
    }

    public function test_bundle_detail_returns_correct_channel_count(): void
    {
        $res = $this->getJson("/api/v1/products/{$this->bundleProduct->id}")
            ->assertOk();

        $this->assertSame(2, $res->json('data.channels_count'));
    }
}
