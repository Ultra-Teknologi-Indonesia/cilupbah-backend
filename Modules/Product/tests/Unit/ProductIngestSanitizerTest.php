<?php

namespace Modules\Product\Tests\Unit;

use Modules\Product\Support\ProductIngestSanitizer;
use Tests\TestCase;

class ProductIngestSanitizerTest extends TestCase
{
    public function test_truncates_name_and_codes_to_column_limit(): void
    {
        $out = ProductIngestSanitizer::sanitize([
            'name' => str_repeat('A', 400),
            'sku' => str_repeat('S', 300),
            'variants' => [
                ['sku' => str_repeat('V', 300), 'barcode' => str_repeat('B', 300)],
            ],
        ]);

        $this->assertSame(255, mb_strlen($out['name']));
        $this->assertSame(255, mb_strlen($out['sku']));
        $this->assertSame(255, mb_strlen($out['variants'][0]['sku']));
        $this->assertSame(255, mb_strlen($out['variants'][0]['barcode']));
    }

    public function test_blank_sku_and_barcode_become_null(): void
    {
        $out = ProductIngestSanitizer::sanitize([
            'name' => 'X',
            'sku' => '',
            'variants' => [['sku' => '   ', 'barcode' => '']],
        ]);

        $this->assertNull($out['sku']);
        $this->assertNull($out['variants'][0]['sku']);
        $this->assertNull($out['variants'][0]['barcode']);
    }

    public function test_dedupes_duplicate_variant_sku_within_product(): void
    {
        $out = ProductIngestSanitizer::sanitize([
            'name' => 'Soft Case',
            'variants' => [
                ['sku' => 'ROSE-RED-XR', 'sell_price' => 100],
                ['sku' => 'ROSE-RED-XR', 'sell_price' => 200],
                ['sku' => 'BLUE-XR', 'sell_price' => 300],
                ['sku' => null, 'sell_price' => 400],
                ['sku' => null, 'sell_price' => 500],
            ],
        ]);

        $skus = array_map(fn ($v) => $v['sku'], $out['variants']);
        $this->assertSame(['ROSE-RED-XR', 'BLUE-XR', null, null], $skus);
        $this->assertSame(100, $out['variants'][0]['sell_price']);
    }

    public function test_nullifies_duplicate_variant_barcode_within_product(): void
    {
        $out = ProductIngestSanitizer::sanitize([
            'name' => 'X',
            'variants' => [
                ['sku' => 'A', 'barcode' => '123'],
                ['sku' => 'B', 'barcode' => '123'],
            ],
        ]);

        $this->assertSame('123', $out['variants'][0]['barcode']);
        $this->assertNull($out['variants'][1]['barcode']);
    }

    public function test_drops_invalid_media_urls_keeps_http(): void
    {
        $out = ProductIngestSanitizer::sanitize([
            'name' => 'X',
            'media' => [
                ['url' => 'https://cdn.example/a.jpg'],
                ['url' => 'not-a-url'],
                ['url' => ''],
                ['url' => 'http://cdn.example/b.jpg'],
            ],
        ]);

        $this->assertCount(2, $out['media']);
        $this->assertSame('https://cdn.example/a.jpg', $out['media'][0]['url']);
        $this->assertSame('http://cdn.example/b.jpg', $out['media'][1]['url']);
    }
}
