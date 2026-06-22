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
        $this->assertSame('Tipe', $attr['name']);
        $this->assertSame('ZB14-I5-512', $attr['value_name']);
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
        $this->assertSame('100000', $sku['sales_attributes'][0]['id']);
        $this->assertSame('ZB14-I5-512', $sku['sales_attributes'][0]['value_name']);
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

    public function test_dynamic_sales_attribute_map_resolves_by_attribute_id(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            [
                'sku' => 'ZB14-256-8-14', 'sell_price' => 8000000, 'stock' => 5,
                'options' => [
                    ['attribute_id' => 32, 'attribute_name' => 'RAM', 'value' => '256/8'],
                    ['attribute_id' => 12, 'attribute_name' => 'Display Size', 'value' => '14'],
                ],
            ],
            [
                'sku' => 'ZB14-512-16-14', 'sell_price' => 12000000, 'stock' => 5,
                'options' => [
                    ['attribute_id' => 32, 'attribute_name' => 'RAM', 'value' => '512/16'],
                    ['attribute_id' => 12, 'attribute_name' => 'Display Size', 'value' => '14'],
                ],
            ],
        ]), [], [
            'mode' => 'create',
            'sales_attribute_map' => [32 => '200001', 12 => '200002'],
        ]);

        $this->assertCount(2, $payload['skus']);
        $attrs = $payload['skus'][0]['sales_attributes'];
        $this->assertSame('200001', $attrs[0]['id']);
        $this->assertSame('RAM', $attrs[0]['name']);
        $this->assertSame('256/8', $attrs[0]['value_name']);
        $this->assertSame('200002', $attrs[1]['id']);
        $this->assertSame('14', $attrs[1]['value_name']);
    }

    public function test_sales_attribute_uses_tiktok_name_over_internal_name(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            [
                'sku' => 'ZB14-256-8-14', 'sell_price' => 8000000, 'stock' => 5,
                'options' => [
                    ['attribute_id' => 32, 'attribute_name' => 'RAM', 'value' => '256/8'],
                    ['attribute_id' => 12, 'attribute_name' => 'Display Size', 'value' => '14'],
                ],
            ],
        ]), [], [
            'mode' => 'create',
            'sales_attribute_map' => [32 => '100090', 12 => '100000'],
            'sales_attribute_id_to_name' => ['100090' => 'Kapasitas', '100000' => 'Warna'],
        ]);

        $attrs = $payload['skus'][0]['sales_attributes'];
        $this->assertSame('Kapasitas', $attrs[0]['name']);
        $this->assertSame('256/8', $attrs[0]['value_name']);
        $this->assertSame('Warna', $attrs[1]['name']);
        $this->assertSame('14', $attrs[1]['value_name']);
    }

    public function test_name_map_fallback_when_no_id_mapping(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            [
                'sku' => 'SHIRT-RED', 'sell_price' => 100000, 'stock' => 10,
                'options' => [
                    ['attribute_id' => 99, 'attribute_name' => 'Warna', 'value' => 'Merah'],
                ],
            ],
        ]), [], [
            'mode' => 'create',
            'sales_attribute_name_map' => ['warna' => '300001'],
        ]);

        $attr = $payload['skus'][0]['sales_attributes'][0];
        $this->assertSame('300001', $attr['id']);
        $this->assertSame('Merah', $attr['value_name']);
    }

    public function test_unknown_attribute_uses_attribute_name_fallback(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            [
                'sku' => 'ITEM-A', 'sell_price' => 50000, 'stock' => 5,
                'options' => [
                    ['attribute_id' => 777, 'attribute_name' => 'Bahan', 'value' => 'Katun'],
                ],
            ],
        ]), [], ['mode' => 'create']);

        $attr = $payload['skus'][0]['sales_attributes'][0];
        $this->assertArrayNotHasKey('id', $attr);
        $this->assertSame('Bahan', $attr['name']);
        $this->assertSame('Katun', $attr['value_name']);
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

    public function test_options_without_attribute_name_are_skipped(): void
    {
        $mapper = new TikTokProductMapper();

        $payload = $mapper->map($this->product([
            [
                'sku' => 'ZB14-I5-512', 'sell_price' => 12000000, 'stock' => 5,
                'options' => [
                    ['attribute_id' => null, 'attribute_name' => null, 'value' => 'some-val'],
                ],
            ],
        ]), [], ['mode' => 'create']);

        $this->assertArrayNotHasKey('sales_attributes', $payload['skus'][0]);
    }
}
