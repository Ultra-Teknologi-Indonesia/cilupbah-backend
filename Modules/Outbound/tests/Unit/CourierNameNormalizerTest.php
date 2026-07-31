<?php

namespace Modules\Outbound\Tests\Unit;

use Modules\Outbound\Support\CourierNameNormalizer;
use PHPUnit\Framework\TestCase;

class CourierNameNormalizerTest extends TestCase
{
    public function test_cleans_real_marketplace_strings(): void
    {
        // Tanpa @dataProvider: proyek memakai strip-comments yang membuang docblock.
        $cases = [
            ['Drop-off: LEX ID, Delivery: J&T', 'J&T'],
            ['Drop-off: JNE Cashless, Delivery: JNE Cashless', 'JNE Cashless'],
            ['Pickup: J&T CARGO, Delivery: J&T CARGO', 'J&T CARGO'],
            ['Drop-off: Grab-ID, Delivery: Grab-ID', 'Grab-ID'],
            ['TT Virtual# JNT express', 'JNT express'],
            ['TT Virtual# Ninja Van Malaysia', 'Ninja Van Malaysia'],
            ["Sandbox-J&T Cargo(Don't modify)", 'J&T Cargo'],
            ["Sandbox-J&T Express(Don't modify)", 'J&T Express'],
            ['Global Standard Shipping(Test)', 'Global Standard Shipping'],
            ['SPX Instant', 'SPX Instant'],
            ['Delivered by Seller', 'Delivered by Seller'],
            ['', ''],
        ];

        foreach ($cases as [$raw, $expected]) {
            $this->assertSame($expected, CourierNameNormalizer::clean($raw), "clean({$raw})");
        }
    }

    public function test_null_returns_empty_string(): void
    {
        $this->assertSame('', CourierNameNormalizer::clean(null));
    }
}
