<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Support\ChannelModelLinker;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\ProductRepository;
use Modules\Product\Services\ChannelSkuHealth;
use Modules\Product\Services\ProductService;
use Tests\TestCase;

class BundleChannelSkuLinkerTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);

        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHP-BUNDLE',
            'shop_name' => 'Shopee Bundle',
            'is_active' => true,
        ]);
    }

    public function test_download_links_each_bundle_seller_sku_to_its_own_bundle(): void
    {
        $firstBundle = $this->makeBundle('BUNDLE-JELLY-A');
        $secondBundle = $this->makeBundle('BUNDLE-JELLY-B');

        $matchedExisting = false;
        $variantIds = [];
        $defaultProductId = app(ProductService::class)->upsertFromChannel([
            'variants' => [
                ['sku' => 'BUNDLE-JELLY-A'],
                ['sku' => 'BUNDLE-JELLY-B'],
            ],
        ], $matchedExisting, $variantIds, true);

        $this->assertTrue($matchedExisting);
        $this->assertContains($defaultProductId, [$firstBundle->id, $secondBundle->id]);
        $this->assertSame(2, Product::count(), 'Download bundle tidak boleh membuat master produk baru.');

        $repository = app(ChannelProductRepository::class);
        $defaultPcmId = $repository->upsertChannelMapping(
            (string) $defaultProductId,
            $this->shop->shop_id,
            'LISTING-JELLY-1',
            'synced',
            null,
            false,
        );

        $staleBundle = $defaultProductId === $firstBundle->id ? $secondBundle : $firstBundle;
        $staleTechnicalVariant = app(ProductRepository::class)->ensureActiveBundleVariant($staleBundle);
        $repository->upsertVariantChannelMapping(
            $defaultPcmId,
            (string) $staleTechnicalVariant->id,
            'STALE-MODEL',
            (string) $staleBundle->sku,
            50000,
        );

        app(ChannelModelLinker::class)->link(
            $this->shop,
            $this->shop->shop_id,
            'LISTING-JELLY-1',
            [
                [
                    'sku' => 'BUNDLE-JELLY-A',
                    'external_sku_id' => 'MODEL-A',
                    'price' => 50000,
                    'variant' => [],
                ],
                [
                    'sku' => 'BUNDLE-JELLY-B',
                    'external_sku_id' => 'MODEL-B',
                    'price' => 50000,
                    'variant' => [],
                ],
            ],
            (string) $defaultProductId,
            $defaultPcmId,
        );

        $mappings = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $this->shop->id)
            ->where('external_product_id', 'LISTING-JELLY-1')
            ->get()
            ->keyBy('product_id');

        $this->assertCount(2, $mappings, 'Satu listing boleh tertaut ke beberapa bundle melalui SKU model.');
        $this->assertArrayHasKey($firstBundle->id, $mappings->all());
        $this->assertArrayHasKey($secondBundle->id, $mappings->all());

        $firstTechnicalVariant = app(ProductRepository::class)->ensureActiveBundleVariant($firstBundle);
        $secondTechnicalVariant = app(ProductRepository::class)->ensureActiveBundleVariant($secondBundle);

        $this->assertDatabaseHas('product_variant_channel_mappings', [
            'product_channel_mapping_id' => $mappings[$firstBundle->id]->id,
            'variant_id' => $firstTechnicalVariant->id,
            'external_sku_id' => 'MODEL-A',
            'channel_seller_sku' => 'BUNDLE-JELLY-A',
        ]);
        $this->assertDatabaseHas('product_variant_channel_mappings', [
            'product_channel_mapping_id' => $mappings[$secondBundle->id]->id,
            'variant_id' => $secondTechnicalVariant->id,
            'external_sku_id' => 'MODEL-B',
            'channel_seller_sku' => 'BUNDLE-JELLY-B',
        ]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', [
            'external_sku_id' => 'STALE-MODEL',
        ]);

        $this->assertSame($firstBundle->id, $firstTechnicalVariant->fresh()->product_id);
        $this->assertSame($secondBundle->id, $secondTechnicalVariant->fresh()->product_id);
        $this->assertSame(0, app(ChannelSkuHealth::class)->listingTerpecah());
    }

    public function test_mixed_listing_creates_regular_master_without_copying_bundle_skus(): void
    {
        $bundle = $this->makeBundle('BUNDLE-MIXED-A');
        $category = Category::firstOrCreate(['name' => 'Bundle Test']);
        $matchedExisting = false;
        $variantIds = [];

        $regularProductId = app(ProductService::class)->upsertFromChannel([
            'name' => 'Nama Listing Marketplace',
            'category_id' => $category->id,
            'variants' => [
                ['sku' => 'BUNDLE-MIXED-A'],
                ['sku' => 'REGULAR-MIXED-A'],
            ],
        ], $matchedExisting, $variantIds, true);

        $this->assertFalse($matchedExisting);
        $this->assertNotSame($bundle->id, $regularProductId);
        $this->assertSame('Nama Listing Marketplace', Product::findOrFail($regularProductId)->name);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $regularProductId,
            'sku' => 'REGULAR-MIXED-A',
        ]);
        $this->assertDatabaseMissing('product_variants', [
            'product_id' => $regularProductId,
            'sku' => 'BUNDLE-MIXED-A',
        ]);
    }

    private function makeBundle(string $sku): Product
    {
        $category = Category::firstOrCreate(['name' => 'Bundle Test']);

        $bundle = Product::create([
            'name' => $sku,
            'sku' => $sku,
            'category_id' => $category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => true,
        ]);

        app(ProductRepository::class)->ensureActiveBundleVariant($bundle);

        return $bundle;
    }
}
