<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\WebhookProductHandler;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class ProductReviewStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeMapping(string $shopId = 'SHOP1', string $externalId = 'EXT1'): ProductChannelMapping
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => $shopId,
            'shop_name' => 'Toko Uji',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Produk Uji',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        return ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $externalId,
            'sync_status' => ProductChannelMapping::STATUS_SYNCING,
        ]);
    }

    public function test_model_markers_set_review_statuses(): void
    {
        $mapping = $this->makeMapping();

        $mapping->markInReview('EXT9');
        $this->assertSame('in_review', $mapping->fresh()->sync_status);
        $this->assertSame('EXT9', $mapping->fresh()->external_product_id);

        $mapping->markApproved();
        $this->assertSame('synced', $mapping->fresh()->sync_status);
        $this->assertNotNull($mapping->fresh()->reviewed_at);

        $mapping->markRejected('Gambar tidak sesuai');
        $fresh = $mapping->fresh();
        $this->assertSame('rejected', $fresh->sync_status);
        $this->assertSame('Gambar tidak sesuai', $fresh->error_message);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_tiktok_webhook_maps_review_results(): void
    {
        $handler = app(WebhookProductHandler::class);

        $m = $this->makeMapping('SHOP-A', 'EXT-A');
        $handler->handleProductStatusChange(['product_id' => 'EXT-A', 'status' => '2'], 'SHOP-A');
        $this->assertSame('in_review', $m->fresh()->sync_status);

        $handler->handleProductStatusChange(['product_id' => 'EXT-A', 'status' => '4'], 'SHOP-A');
        $this->assertSame('synced', $m->fresh()->sync_status);
        $this->assertNotNull($m->fresh()->reviewed_at);

        $handler->handleProductStatusChange(
            ['product_id' => 'EXT-A', 'status' => '3', 'suspend_reason' => 'Konten dilarang'],
            'SHOP-A'
        );
        $rejected = $m->fresh();
        $this->assertSame('rejected', $rejected->sync_status);
        $this->assertSame('Konten dilarang', $rejected->error_message);

        $handler->handleProductStatusChange(['product_id' => 'EXT-A', 'status' => '5'], 'SHOP-A');
        $this->assertSame('deactivated', $m->fresh()->sync_status);
    }
}
