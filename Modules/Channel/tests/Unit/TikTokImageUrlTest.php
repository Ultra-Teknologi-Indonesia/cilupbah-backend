<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Support\TikTokImageUrl;
use PHPUnit\Framework\TestCase;

class TikTokImageUrlTest extends TestCase
{
    public function test_bare_object_uri_is_expanded_to_absolute_url(): void
    {
        $this->assertSame(
            'https://p16-oec-ttp.tiktokcdn-us.com/tos-alisg-i-aphluv4xwc-sg/4c97b2ee84284f78bdc2af1bb0656a1f',
            TikTokImageUrl::ensureFetchable('tos-alisg-i-aphluv4xwc-sg/4c97b2ee84284f78bdc2af1bb0656a1f')
        );
    }

    public function test_full_url_is_returned_untouched(): void
    {
        $url = 'https://p16-oec-ttp.tiktokcdn-us.com/region/79fc3~tplv-aphluv4xwc-origin-jpeg.jpeg';
        $this->assertSame($url, TikTokImageUrl::ensureFetchable($url));

        $shopee = 'https://assets.ultra-fit.id/5/id-11134207-81z1k-mpoc5zd3f8jl2c.jpeg';
        $this->assertSame($shopee, TikTokImageUrl::ensureFetchable($shopee));
    }

    public function test_empty_and_unrecognised_values_return_null(): void
    {
        $this->assertNull(TikTokImageUrl::ensureFetchable(null));
        $this->assertNull(TikTokImageUrl::ensureFetchable(''));
        $this->assertNull(TikTokImageUrl::ensureFetchable('   '));
        $this->assertNull(TikTokImageUrl::ensureFetchable('not-a-url-nor-tos'));
    }
}
