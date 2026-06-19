<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Modules\Channel\Services\ChannelMediaResolver;
use Modules\Channel\Services\LazadaProductMapper;
use Modules\Channel\Services\LazadaToInternalProductMapper;
use Modules\Channel\Services\ShopeeClient;
use Modules\Channel\Services\ShopeeMediaUploader;
use Modules\Channel\Services\ShopeeProductMapper;
use Modules\Channel\Services\ShopeeToInternalProductMapper;
use Modules\Channel\Services\TikTokToInternalProductMapper;
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

    public function test_shopee_inbound_maps_per_variant_image_from_tier_option(): void
    {
        $item = [
            'item_id' => 555,
            'item_name' => 'Kaos',
            'image' => ['image_url_list' => ['https://img/main.jpg']],
            'tier_variation' => [[
                'name' => 'Warna',
                'option_list' => [
                    ['option' => 'Merah', 'image' => ['image_url' => 'https://img/merah.jpg']],
                    ['option' => 'Biru'],
                ],
            ]],
            'model_list' => [
                ['model_id' => 1, 'model_sku' => 'V1', 'tier_index' => [0], 'price_info' => [['current_price' => 1000]]],
                ['model_id' => 2, 'model_sku' => 'V2', 'tier_index' => [1], 'price_info' => [['current_price' => 2000]]],
            ],
        ];

        $internal = app(ShopeeToInternalProductMapper::class)->map($item, 'SHOP-1');

        $v1 = collect($internal['variants'])->firstWhere('sku', 'V1');
        $v2 = collect($internal['variants'])->firstWhere('sku', 'V2');
        $this->assertSame('https://img/merah.jpg', $v1['media'][0]['url']);
        $this->assertArrayNotHasKey('media', $v2);
    }

    public function test_lazada_inbound_maps_per_sku_image(): void
    {
        $product = [
            'attributes' => ['name' => 'Kaos'],
            'images' => ['https://img/main.jpg'],
            'skus' => [
                ['SellerSku' => 'V1', 'price' => 1000, 'Images' => ['https://img/v1.jpg']],
                ['SellerSku' => 'V2', 'price' => 2000],
            ],
        ];

        $internal = app(LazadaToInternalProductMapper::class)->map($product, 'SHOP-1');

        $v1 = collect($internal['variants'])->firstWhere('sku', 'V1');
        $v2 = collect($internal['variants'])->firstWhere('sku', 'V2');
        $this->assertSame('https://img/v1.jpg', $v1['media'][0]['url']);
        $this->assertArrayNotHasKey('media', $v2);
    }

    public function test_tiktok_inbound_maps_per_sku_image(): void
    {
        $product = [
            'title' => 'Kaos',
            'skus' => [
                ['seller_sku' => 'V1', 'sales_attributes' => [['sku_img' => ['url_list' => ['https://img/v1.jpg']]]]],
                ['seller_sku' => 'V2', 'sales_attributes' => [['value_name' => 'Biru']]],
            ],
        ];

        $internal = app(TikTokToInternalProductMapper::class)->map($product, 'SHOP-1');

        $v1 = collect($internal['variants'])->firstWhere('sku', 'V1');
        $v2 = collect($internal['variants'])->firstWhere('sku', 'V2');
        $this->assertSame('https://img/v1.jpg', $v1['media'][0]['url']);
        $this->assertArrayNotHasKey('media', $v2);
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
