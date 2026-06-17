<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\TikTokProductMapper;
use Tests\TestCase;

class TikTokProductMapperTest extends TestCase
{
    private function product(array $variants): array
    {
        return [
            'name' => 'ASUS Zenbook 14 OLED Series',
            'description' => 'Laptop',
            'weight' => 1.5,
            'variants' => $variants,
        ];
    }

    public function test_create_synthesizes_named_sale_attribute_for_multivariant_without_options(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            ['sku' => 'ZB14-I5-512', 'sell_price' => 12000000, 'stock' => 5, 'options' => []],
            ['sku' => 'ZB14-I7-1TB', 'sell_price' => 15000000, 'stock' => 5, 'options' => []],
        ]), [], ['mode' => 'create']);

        $this->assertCount(2, $payload['skus']);
        $attr = $payload['skus'][0]['sales_attributes'][0];
        $this->assertSame('Tipe', $attr['attribute_name']);
        $this->assertSame('ZB14-I5-512', $attr['custom_value']);
    }

    public function test_update_throws_when_multivariant_has_no_resolvable_attribute_id(): void
    {
        $mapper = new TikTokProductMapper();

        $this->expectException(\RuntimeException::class);

        $mapper->map($this->product([
            ['sku' => 'ZB14-I5-512', 'sell_price' => 12000000, 'stock' => 5, 'options' => []],
            ['sku' => 'ZB14-I7-1TB', 'sell_price' => 15000000, 'stock' => 5, 'options' => []],
        ]), [], ['mode' => 'update']);
    }

    public function test_update_reuses_pre_resolved_sale_attribute_id_and_sku_id(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            [
                'sku' => 'ZB14-I5-512', 'sell_price' => 12000000, 'stock' => 5, 'options' => [],
                'external_sku_id' => 'TT-SKU-1',
                'sales_attributes' => [[
                    'attribute_id' => '100000', 'attribute_name' => 'Tipe', 'custom_value' => 'ZB14-I5-512',
                ]],
            ],
        ]), [], ['mode' => 'update']);

        $sku = $payload['skus'][0];
        $this->assertSame('TT-SKU-1', $sku['id']);
        $this->assertSame('100000', $sku['sales_attributes'][0]['attribute_id']);
        $this->assertSame('ZB14-I5-512', $sku['sales_attributes'][0]['custom_value']);
    }

    public function test_map_throws_when_title_shorter_than_25_chars(): void
    {
        $mapper = new TikTokProductMapper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(422);

        $mapper->map([
            'name' => 'Erigo Hoodie',
            'variants' => [['sku' => 'A', 'sell_price' => 1000]],
        ], [], ['mode' => 'create']);
    }

    public function test_map_accepts_title_within_25_and_255_chars(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map([
            'name' => 'Erigo Hoodie Original Premium Unisex',
            'variants' => [['sku' => 'A', 'sell_price' => 1000]],
        ], [], ['mode' => 'create']);

        $this->assertSame('Erigo Hoodie Original Premium Unisex', $payload['title']);
    }

    public function test_never_emits_sale_attribute_lacking_both_id_and_name(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            [
                'sku' => 'ZB14-I5-512', 'sell_price' => 12000000, 'stock' => 5, 'options' => [],
                'sales_attributes' => [['custom_value' => 'ZB14-I5-512']],
            ],
        ]), [], ['mode' => 'create']);

        $this->assertArrayNotHasKey('sales_attributes', $payload['skus'][0]);
    }
}
