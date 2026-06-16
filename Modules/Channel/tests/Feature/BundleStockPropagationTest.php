<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductRepository;
use Tests\TestCase;

class BundleStockPropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
    }

    private function variant(string $sku, bool $isBundle = false): ProductVariant
    {
        $product = Product::create([
            'name' => $sku.' product',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => $isBundle,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    private function mapProductToChannel(string $productId): string
    {
        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-1',
            'shop_name' => 'Toko 1',
            'is_active' => true,
        ]);
        ProductChannelMapping::create([
            'product_id' => $productId,
            'channel_shop_id' => $shop->id,
            'external_product_id' => 'EXT-1',
            'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);

        return $shop->id;
    }

    public function test_job_propagates_stock_sync_to_affected_bundle(): void
    {
        $component = $this->variant('COMP-1');

        $bundleVar = $this->variant('BUNDLE-1', true);
        $bundleVar->product->bundleItems()->create(['component_variant_id' => $component->id, 'qty' => 2]);

        $shopId = $this->mapProductToChannel($bundleVar->product_id);

        Queue::fake();
        (new SyncStockToChannelsJob($component->id))->handle(app(ProductRepository::class));

        Queue::assertPushed(
            SyncProductToChannelJob::class,
            fn ($job) => $job->productId === $bundleVar->product_id
                && $job->channelShopId === $shopId
                && $job->action === 'sync_price_stock'
        );
    }

    public function test_job_no_propagation_when_variant_not_a_component(): void
    {
        $lone = $this->variant('LONE-1');

        Queue::fake();
        (new SyncStockToChannelsJob($lone->id))->handle(app(ProductRepository::class));

        Queue::assertNotPushed(SyncProductToChannelJob::class);
    }
}
