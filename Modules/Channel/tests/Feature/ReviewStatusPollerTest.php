<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ReviewStatusPoller;
use Modules\Channel\Services\TikTokProductService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class ReviewStatusPollerTest extends TestCase
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

    public function test_polling_syncs_review_status_to_db(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);
        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-T',
            'shop_name' => 'Toko',
            'access_token' => 'tok',
            'is_active' => true,
        ]);
        $approved = $this->mapping($shop, 'T-A', ProductChannelMapping::STATUS_IN_REVIEW);
        $rejected = $this->mapping($shop, 'T-B', ProductChannelMapping::STATUS_SYNCING);
        $review   = $this->mapping($shop, 'T-C', ProductChannelMapping::STATUS_SYNCED);
        $missing  = $this->mapping($shop, 'T-D', ProductChannelMapping::STATUS_IN_REVIEW);

        $this->mock(TikTokProductService::class, function ($m) {
            $m->shouldReceive('fetchProductStatuses')->once()->andReturn([
                'T-A' => ['status' => '4', 'reason' => null],
                'T-B' => ['status' => '3', 'reason' => 'Gambar buram'],
                'T-C' => ['status' => '2', 'reason' => null],
                // T-D tidak ada di marketplace → tidak diubah
            ]);
        });

        $summary = app(ReviewStatusPoller::class)->pollShop($shop);

        $this->assertSame(3, $summary['checked']);
        $this->assertSame(3, $summary['updated']);

        $this->assertSame('synced', $approved->fresh()->sync_status);
        $this->assertNotNull($approved->fresh()->reviewed_at);

        $this->assertSame('rejected', $rejected->fresh()->sync_status);
        $this->assertSame('Gambar buram', $rejected->fresh()->error_message);

        $this->assertSame('in_review', $review->fresh()->sync_status);
        $this->assertSame('in_review', $missing->fresh()->sync_status);
    }
}
