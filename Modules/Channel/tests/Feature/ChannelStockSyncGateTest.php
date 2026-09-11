<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Adapters\ShopeeAdapter;
use Modules\Channel\Adapters\TikTokAdapter;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ChannelStockSyncGateTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $this->shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    private function makeListedProduct(array $variantSpecs): Product
    {
        $category = Category::create(['name' => 'C'.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Kaos Polos',
            'status' => 'master', 'is_active' => true,
        ]);

        $listing = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555001',
            'sync_status' => 'synced',
        ]);

        foreach ($variantSpecs as $spec) {
            $variant = ProductVariant::create([
                'product_id' => $product->id, 'sku' => $spec['sku'], 'sell_price' => 50000, 'is_active' => true,
            ]);
            ProductVariantChannelMapping::create([
                'product_channel_mapping_id' => $listing->id,
                'variant_id' => $variant->id,
                'external_sku_id' => $spec['model_id'],
                'sync_enabled' => $spec['sync_enabled'],
            ]);
        }

        return $product->fresh(['variants']);
    }

    public function test_disabled_variant_is_excluded_from_price_and_stock_payload(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/update_price*' => Http::response(['response' => []], 200),
            'partner.shopeemobile.com/api/v2/product/update_stock*' => Http::response(['response' => []], 200),
        ]);

        $product = $this->makeListedProduct([
            ['sku' => 'SKU-ON', 'model_id' => '111', 'sync_enabled' => true],
            ['sku' => 'SKU-OFF', 'model_id' => '222', 'sync_enabled' => false],
        ]);

        $result = app(ShopeeAdapter::class)->syncPriceAndStock($product, $this->shop, '555001');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/update_price')) {
                return false;
            }
            $modelIds = array_column($request['price_list'] ?? [], 'model_id');

            return in_array(111, $modelIds, true) && ! in_array(222, $modelIds, true);
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/update_stock')) {
                return false;
            }
            $modelIds = array_column($request['stock_list'] ?? [], 'model_id');

            return in_array(111, $modelIds, true) && ! in_array(222, $modelIds, true);
        });
    }

    public function test_all_disabled_variants_short_circuit_without_api_call(): void
    {
        Http::fake();

        $product = $this->makeListedProduct([
            ['sku' => 'SKU-OFF-1', 'model_id' => '333', 'sync_enabled' => false],
            ['sku' => 'SKU-OFF-2', 'model_id' => '444', 'sync_enabled' => false],
        ]);

        $result = app(ShopeeAdapter::class)->syncPriceAndStock($product, $this->shop, '555001');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_stock_payload_is_limited_to_the_requested_listing(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/update_stock*' => Http::response(['response' => []], 200),
        ]);

        $product = $this->makeListedProduct([
            ['sku' => 'SKU-LISTING-A', 'model_id' => '111', 'sync_enabled' => true],
        ]);
        $listingA = ProductChannelMapping::where('product_id', $product->id)->firstOrFail();

        $listingB = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555002',
            'sync_status' => 'synced',
        ]);
        $variantB = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-LISTING-B',
            'sell_price' => 50000,
            'is_active' => true,
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listingB->id,
            'variant_id' => $variantB->id,
            'external_sku_id' => '222',
            'sync_enabled' => true,
        ]);

        $result = app(ShopeeAdapter::class)->syncStock(
            $product,
            $this->shop,
            '555001',
            $listingA,
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/update_stock')) {
                return false;
            }

            return ($request['item_id'] ?? null) === 555001
                && array_column($request['stock_list'] ?? [], 'model_id') === [111];
        });
    }

    public function test_generic_stock_job_fans_out_one_job_per_listing(): void
    {
        Queue::fake();

        $product = $this->makeListedProduct([
            ['sku' => 'SKU-LISTING-A', 'model_id' => '111', 'sync_enabled' => true],
        ]);
        $listingA = ProductChannelMapping::where('product_id', $product->id)->firstOrFail();
        $listingB = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555002',
            'sync_status' => 'synced',
        ]);
        $variantB = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-LISTING-B',
            'sell_price' => 50000,
            'is_active' => true,
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listingB->id,
            'variant_id' => $variantB->id,
            'external_sku_id' => '222',
            'sync_enabled' => true,
        ]);

        (new SyncProductToChannelJob($product->id, $this->shop->id, 'sync_stock'))
            ->handle(app(AdapterFactory::class));

        Queue::assertPushed(
            SyncProductToChannelJob::class,
            fn (SyncProductToChannelJob $job): bool => $job->channelMappingId === $listingA->id,
        );
        Queue::assertPushed(
            SyncProductToChannelJob::class,
            fn (SyncProductToChannelJob $job): bool => $job->channelMappingId === $listingB->id,
        );
    }

    public function test_tiktok_stock_payload_is_limited_to_the_requested_listing(): void
    {
        config([
            'services.tiktok.app_key' => 'test-key',
            'services.tiktok.app_secret' => 'test-secret',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
        ]);

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $tiktokShop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'TT-SHOP-1',
            'shop_name' => 'TikTok Test',
            'shop_cipher' => 'cipher',
            'access_token' => 'token',
            'is_active' => true,
        ]);
        $location = Location::factory()->create();
        DB::table('channel_warehouses')->insert([
            'location_id' => $location->id,
            'channel_id' => $tiktok->id,
            'store_id' => $tiktokShop->shop_id,
            'channel_location_id' => 'TT-WH-1',
            'channel_location_type' => 'SALES_WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = $this->makeListedProduct([
            ['sku' => 'SKU-TT-A', 'model_id' => '111', 'sync_enabled' => true],
        ]);
        $variantA = $product->variants->firstOrFail();
        $listingA = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $tiktokShop->id,
            'external_product_id' => 'TT-LISTING-A',
            'sync_status' => 'synced',
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listingA->id,
            'variant_id' => $variantA->id,
            'external_sku_id' => 'TT-SKU-A',
            'sync_enabled' => true,
        ]);
        $variantB = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-TT-B',
            'sell_price' => 50000,
            'is_active' => true,
        ]);
        $listingB = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $tiktokShop->id,
            'external_product_id' => 'TT-LISTING-B',
            'sync_status' => 'synced',
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listingB->id,
            'variant_id' => $variantB->id,
            'external_sku_id' => 'TT-SKU-B',
            'sync_enabled' => true,
        ]);

        Http::fake([
            'open-api.tiktokglobalshop.com/product/202309/products/TT-LISTING-A/inventory/update*' => Http::response(['code' => 0], 200),
        ]);

        $result = app(TikTokAdapter::class)->syncStock(
            $product,
            $tiktokShop,
            'TT-LISTING-A',
            $listingA,
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/products/TT-LISTING-A/inventory/update')
                && array_column($request['skus'] ?? [], 'id') === ['TT-SKU-A'];
        });
    }

    public function test_missing_tiktok_sku_id_stops_before_an_api_request(): void
    {
        Http::fake();

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $tiktokShop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'TT-SHOP-2',
            'shop_name' => 'TikTok Validation Test',
            'shop_cipher' => 'cipher',
            'access_token' => 'token',
            'is_active' => true,
        ]);
        $product = $this->makeListedProduct([
            ['sku' => 'SKU-TT-EMPTY', 'model_id' => '111', 'sync_enabled' => true],
        ]);
        $variant = $product->variants->firstOrFail();
        $listing = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $tiktokShop->id,
            'external_product_id' => 'TT-LISTING-EMPTY',
            'sync_status' => 'synced',
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listing->id,
            'variant_id' => $variant->id,
            'external_sku_id' => null,
            'sync_enabled' => true,
        ]);

        $result = app(TikTokAdapter::class)->syncStock(
            $product,
            $tiktokShop,
            'TT-LISTING-EMPTY',
            $listing,
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SKU ID TikTok belum lengkap', $result['message']);
        Http::assertNothingSent();
    }
}
