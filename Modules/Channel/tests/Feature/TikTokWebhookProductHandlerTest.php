<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\WebhookProductHandler;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class TikTokWebhookProductHandlerTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;
    private ProductChannelMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'TT-1',
            'shop_name' => 'Toko TikTok',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'C', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'P', 'status' => 'master', 'is_active' => true]);
        $this->mapping = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => 'TP-1',
            'sync_status' => 'synced',
        ]);
    }

    public function test_product_update_event_pulls_full_product_content(): void
    {
        $download = Mockery::mock(ChannelDownloadService::class);
        $download->shouldReceive('downloadProductDebounced')->once()->with('tiktok', 'TT-1', 'TP-1');

        (new WebhookProductHandler($download))->handleProductUpdate(
            ['product_id' => 'TP-1'],
            'TT-1',
        );
    }

    public function test_missing_mapping_skips_pull(): void
    {
        $download = Mockery::mock(ChannelDownloadService::class);
        $download->shouldReceive('downloadProductDebounced')->never();

        (new WebhookProductHandler($download))->handleProductUpdate(
            ['product_id' => 'TP-UNKNOWN'],
            'TT-1',
        );
    }
}
