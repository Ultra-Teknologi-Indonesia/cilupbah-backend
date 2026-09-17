<?php

namespace Modules\Product\Tests\Unit;

use Modules\Product\Support\ChannelSku;
use Tests\TestCase;

class ChannelSkuTest extends TestCase
{
    public function test_internal_bundle_sku_is_not_accepted_as_channel_sku(): void
    {
        $technicalSku = '__bundle__019fe9c7-b7a6-72f8-89a6-22f875b1dd54';

        $this->assertTrue(ChannelSku::isTechnicalBundleSku($technicalSku));
        $this->assertNull(ChannelSku::normalize($technicalSku));
        $this->assertStringContainsString('SKU teknis internal bundle', ChannelSku::reason($technicalSku));
    }

    public function test_regular_seller_sku_remains_accepted(): void
    {
        $this->assertFalse(ChannelSku::isTechnicalBundleSku('BUNDLE-SKU-01'));
        $this->assertSame('BUNDLE-SKU-01', ChannelSku::normalize(' BUNDLE-SKU-01 '));
    }
}
