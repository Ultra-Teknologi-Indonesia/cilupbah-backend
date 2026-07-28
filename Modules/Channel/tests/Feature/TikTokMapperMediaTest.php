<?php

namespace Modules\Channel\Tests\Feature;

use Modules\Channel\Services\TikTokProductMapper;
use Tests\TestCase;

class TikTokMapperMediaTest extends TestCase
{
    private function mapper(): TikTokProductMapper
    {
        return new TikTokProductMapper();
    }

    private function cfg(array $overrides = []): array
    {
        return array_merge(['warehouse_id' => '7646426075561690887'], $overrides);
    }

    public function test_main_images_capped_at_9(): void
    {
        $uris = array_map(fn ($i) => "uri-$i", range(1, 12));

        $payload = $this->mapper()->map(['name' => 'Produk Uji Media TikTok 123', 'variants' => []], $uris, $this->cfg());

        $this->assertCount(9, $payload['main_images']);
        $this->assertSame(['uri' => 'uri-1'], $payload['main_images'][0]);
    }

    public function test_video_included_when_video_id_present(): void
    {
        $payload = $this->mapper()->map(['name' => 'Produk Uji Media TikTok 123', 'variants' => []], ['u1'], $this->cfg(['video_id' => 'vid-1']));

        $this->assertSame(['id' => 'vid-1'], $payload['video']);
    }

    public function test_no_video_field_without_video_id(): void
    {
        $payload = $this->mapper()->map(['name' => 'Produk Uji Media TikTok 123', 'variants' => []], ['u1'], $this->cfg());

        $this->assertArrayNotHasKey('video', $payload);
    }

    public function test_sku_img_attached_to_first_sales_attribute(): void
    {
        $internal = ['name' => 'Produk Uji Media TikTok 123', 'variants' => [[
            'sku' => 'V1',
            'sell_price' => 1000,
            'options' => [['attribute_name' => 'Warna', 'value' => 'Merah']],
            'image_uri' => 'var-uri-1',
        ]]];

        $payload = $this->mapper()->map($internal, ['u1'], $this->cfg());

        $this->assertSame(['uri' => 'var-uri-1'], $payload['skus'][0]['sales_attributes'][0]['sku_img']);
    }

    public function test_multi_variant_without_options_gets_synthesized_sales_attribute(): void
    {
        $internal = ['name' => 'Produk Uji Media TikTok 123', 'variants' => [
            ['sku' => 'ZB-I5', 'sell_price' => 1000],
            ['sku' => 'ZB-I7', 'sell_price' => 2000],
        ]];

        $payload = $this->mapper()->map($internal, ['u1'], $this->cfg());

        $this->assertSame('Tipe', $payload['skus'][0]['sales_attributes'][0]['attribute_name']);
        $this->assertSame('ZB-I5', $payload['skus'][0]['sales_attributes'][0]['custom_value']);
        $this->assertSame('ZB-I7', $payload['skus'][1]['sales_attributes'][0]['custom_value']);
    }

    public function test_single_variant_without_options_has_no_sales_attribute(): void
    {
        $internal = ['name' => 'Produk Uji Media TikTok 123', 'variants' => [['sku' => 'SOLO', 'sell_price' => 1000]]];

        $payload = $this->mapper()->map($internal, ['u1'], $this->cfg());

        $this->assertArrayNotHasKey('sales_attributes', $payload['skus'][0]);
    }

    public function test_no_sku_img_when_variant_has_no_options(): void
    {
        $internal = ['name' => 'Produk Uji Media TikTok 123', 'variants' => [[
            'sku' => 'V1',
            'sell_price' => 1000,
            'image_uri' => 'var-uri-1',
        ]]];

        $payload = $this->mapper()->map($internal, ['u1'], $this->cfg());

        $this->assertArrayNotHasKey('sales_attributes', $payload['skus'][0]);
    }
}
