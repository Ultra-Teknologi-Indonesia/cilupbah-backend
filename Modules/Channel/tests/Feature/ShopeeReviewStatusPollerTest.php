<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ReviewStatusPoller;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class ShopeeReviewStatusPollerTest extends TestCase
{
    use RefreshDatabase;

    private function mapping(ChannelShop $shop, string $extId, string $status): ProductChannelMapping
    {
        $product = Product::create([
            'name' => 'P-' . $extId,
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        return ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $extId,
            'sync_status' => $status,
        ]);
    }

    public function test_shopee_polling_maps_statuses_correctly(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);
        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-S',
            'shop_name' => 'Toko Shopee',
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $normal = $this->mapping($shop, 'S-A', ProductChannelMapping::STATUS_IN_REVIEW);
        $banned = $this->mapping($shop, 'S-B', ProductChannelMapping::STATUS_SYNCED);
        $deleted = $this->mapping($shop, 'S-C', ProductChannelMapping::STATUS_SYNCED);
        $unlist = $this->mapping($shop, 'S-D', ProductChannelMapping::STATUS_SYNCED);

        $this->mock(ShopeeProductService::class, function ($m) {
            $m->shouldReceive('fetchProductStatuses')->once()->andReturn([
                'S-A' => ['status' => 'normal', 'reason' => null],
                'S-B' => ['status' => 'banned', 'reason' => null],
                'S-C' => ['status' => 'deleted', 'reason' => null],
                'S-D' => ['status' => 'unlist', 'reason' => null],
            ]);
        });

        $summary = app(ReviewStatusPoller::class)->pollShop($shop);

        $this->assertSame(4, $summary['checked']);
        $this->assertSame(4, $summary['updated']);

        $this->assertSame('synced', $normal->fresh()->sync_status);
        $this->assertNotNull($normal->fresh()->reviewed_at);

        $this->assertSame('rejected', $banned->fresh()->sync_status);
        $this->assertStringContainsString('Ditolak marketplace', $banned->fresh()->error_message);

        $this->assertSame('deactivated', $deleted->fresh()->sync_status);
        $this->assertSame('deactivated', $unlist->fresh()->sync_status);
    }

    public function test_poll_all_includes_shopee_shops(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);
        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-S2',
            'shop_name' => 'Toko Shopee 2',
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $this->mapping($shop, 'S-X', ProductChannelMapping::STATUS_SYNCED);

        $this->mock(ShopeeProductService::class, function ($m) {
            $m->shouldReceive('fetchProductStatuses')->once()->andReturn([
                'S-X' => ['status' => 'normal', 'reason' => null],
            ]);
        });

        $summary = app(ReviewStatusPoller::class)->pollAll();

        $this->assertSame(1, $summary['shops']);
    }
}
