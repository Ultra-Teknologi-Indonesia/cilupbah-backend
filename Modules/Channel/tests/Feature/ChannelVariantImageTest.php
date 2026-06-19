<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Modules\Channel\Services\ChannelMediaResolver;
use Modules\Channel\Services\LazadaProductMapper;
use Modules\Channel\Services\ShopeeClient;
use Modules\Channel\Services\ShopeeMediaUploader;
use Modules\Channel\Services\ShopeeProductMapper;
use Tests\TestCase;

class ChannelVariantImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lazada_mapper_attaches_per_sku_images(): void
    {
        $product = [
            'name' => 'Kaos',
            'category_id' => null,
            'variants' => [
                ['sku' => 'V1', 'sell_price' => 1000, 'image_url' => 'https://img.test/v1.jpg'],
                ['sku' => 'V2', 'sell_price' => 2000],
            ],
        ];

        $payload = app(LazadaProductMapper::class)->map($product, ['https://img.test/main.jpg']);
        $skus = $payload['Request']['Product']['Skus']['Sku'];

        $this->assertSame(['https://img.test/v1.jpg'], $skus[0]['Images']);
        $this->assertArrayNotHasKey('Images', $skus[1]);
    }

    public function test_shopee_mapper_attaches_image_to_tier_option(): void
    {
        $product = [
            'name' => 'Kaos',
            'category_id' => null,
            'variants' => [
                ['sku' => 'V1', 'sell_price' => 1000, 'options' => [['value' => 'Merah']], 'image_id' => 'shp-img-1'],
                ['sku' => 'V2', 'sell_price' => 2000, 'options' => [['value' => 'Biru']]],
            ],
        ];

        $payload = app(ShopeeProductMapper::class)->map($product, ['main-img-id']);

        $this->assertSame(['main-img-id'], $payload['image']['image_id_list']);

        $optionList = $payload['tier_variation'][0]['option_list'];
        $this->assertSame(['image_id' => 'shp-img-1'], $optionList[0]['image']);
        $this->assertArrayNotHasKey('image', $optionList[1]);
    }

    public function test_shopee_media_uploader_uploads_once_and_caches(): void
    {
        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        Http::fake([
            'partner.shopeemobile.com/api/v2/media_space/upload_image*' => Http::response([
                'response' => ['image_info' => ['image_id' => 'sg-img-1']],
            ], 200),
        ]);

        $resolver = Mockery::mock(ChannelMediaResolver::class);
        $resolver->shouldReceive('bytes')->andReturn('fake-image-bytes');

        $uploader = new ShopeeMediaUploader(app(ShopeeClient::class), $resolver);

        // URL sama dipanggil dua kali → upload sekali, sisanya dari cache.
        $ids = $uploader->uploadFromUrls(['https://img.test/a.jpg', 'https://img.test/a.jpg']);

        $this->assertSame(['sg-img-1', 'sg-img-1'], $ids);
        Http::assertSentCount(1);
    }
}
