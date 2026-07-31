<?php

namespace Modules\Outbound\Tests\Unit;

use Modules\Outbound\Support\CourierNameNormalizer;
use PHPUnit\Framework\TestCase;

class CourierNameNormalizerTest extends TestCase
{
    /**
     * @dataProvider realWorldStrings
     */
    public function test_cleans_real_marketplace_strings(string $raw, string $expected): void
    {
        $this->assertSame($expected, CourierNameNormalizer::clean($raw));
    }

    public static function realWorldStrings(): array
    {
        return [
            // Lazada majemuk -> ambil kurir pengantar (Delivery:)
            'lazada drop-off + delivery beda kurir' => ['Drop-off: LEX ID, Delivery: J&T', 'J&T'],
            'lazada drop-off + delivery sama'       => ['Drop-off: JNE Cashless, Delivery: JNE Cashless', 'JNE Cashless'],
            'lazada pickup + delivery'              => ['Pickup: J&T CARGO, Delivery: J&T CARGO', 'J&T CARGO'],
            'lazada grab'                           => ['Drop-off: Grab-ID, Delivery: Grab-ID', 'Grab-ID'],

            // TikTok virtual prefix
            'tiktok virtual jnt'   => ['TT Virtual# JNT express', 'JNT express'],
            'tiktok virtual ninja' => ['TT Virtual# Ninja Van Malaysia', 'Ninja Van Malaysia'],

            // Shopee sandbox + penanda uji
            'shopee sandbox cargo'   => ["Sandbox-J&T Cargo(Don't modify)", 'J&T Cargo'],
            'shopee sandbox express' => ["Sandbox-J&T Express(Don't modify)", 'J&T Express'],
            'tiktok test suffix'     => ['Global Standard Shipping(Test)', 'Global Standard Shipping'],

            // Yang sudah bersih tetap apa adanya
            'sudah bersih spx'   => ['SPX Instant', 'SPX Instant'],
            'delivered by seller' => ['Delivered by Seller', 'Delivered by Seller'],
            'kosong'             => ['', ''],
        ];
    }

    public function test_null_returns_empty_string(): void
    {
        $this->assertSame('', CourierNameNormalizer::clean(null));
    }
}
