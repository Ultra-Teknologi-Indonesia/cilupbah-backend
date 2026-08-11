<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class DownloadSearchSortTest extends TestCase
{
    use RefreshDatabase;

    private function invokeFlag(string $shopId, array $results): array
    {
        $service = app(ChannelDownloadService::class);
        $ref = new \ReflectionMethod($service, 'flagDownloaded');
        $ref->setAccessible(true);

        return $ref->invoke($service, $shopId, $results);
    }

    public function test_not_downloaded_products_sort_to_top_preserving_channel_order(): void
    {
        $categoryId = Category::create(['name' => 'Cat', 'is_active' => true])->id;
        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'TT-1', 'shop_name' => 'Toko',
            'access_token' => 't', 'is_active' => true,
        ]);

        $product = Product::create(['name' => 'P-B', 'category_id' => $categoryId, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => 'B',
            'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);

        $input = [
            ['external_product_id' => 'A'],
            ['external_product_id' => 'B'],
            ['external_product_id' => 'C'],
        ];

        $out = $this->invokeFlag('TT-1', $input);

        $order = array_column($out, 'external_product_id');
        $this->assertSame(['A', 'C', 'B'], $order, 'belum-download (A,C) di atas, urutan asli terjaga; sudah-download (B) di bawah');

        $flags = [];
        foreach ($out as $r) {
            $flags[$r['external_product_id']] = $r['already_downloaded'];
        }
        $this->assertFalse($flags['A']);
        $this->assertTrue($flags['B']);
        $this->assertFalse($flags['C']);
    }
}
