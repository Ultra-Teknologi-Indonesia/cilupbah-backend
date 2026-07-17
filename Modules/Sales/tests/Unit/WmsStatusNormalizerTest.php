<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Enums\WmsStatus;
use Modules\Sales\Support\WmsStatusNormalizer;
use PHPUnit\Framework\TestCase;

class WmsStatusNormalizerTest extends TestCase
{
    public function test_null_returns_null(): void
    {
        $this->assertNull(WmsStatusNormalizer::normalize('shopee', null));
        $this->assertNull(WmsStatusNormalizer::normalize('shopee', ''));
    }

    public function test_canonical_fastpath(): void
    {
        $this->assertSame(WmsStatus::COMPLETED, WmsStatusNormalizer::normalize('any', 'COMPLETED'));
    }

    public function test_shopee_mapping(): void
    {
        $this->assertSame(WmsStatus::CREATED, WmsStatusNormalizer::normalize('shopee', 'UNPAID'));
        $this->assertSame(WmsStatus::PROCESS, WmsStatusNormalizer::normalize('shopee', 'PROCESSED'));
        $this->assertSame(WmsStatus::CANCELLED, WmsStatusNormalizer::normalize('shopee', 'IN_CANCEL'));
    }

    public function test_tiktok_mapping(): void
    {
        $this->assertSame(WmsStatus::PAID, WmsStatusNormalizer::normalize('tiktok', 'AWAITING_SHIPMENT'));
        $this->assertSame(WmsStatus::SHIPPED, WmsStatusNormalizer::normalize('tiktok', 'IN_TRANSIT'));
    }

    public function test_lazada_lowercase(): void
    {
        $this->assertSame(WmsStatus::PAID, WmsStatusNormalizer::normalize('lazada', 'ready_to_ship'));
        $this->assertSame(WmsStatus::RETURNED, WmsStatusNormalizer::normalize('lazada', 'returned'));
    }

    public function test_unknown_falls_back_to_other(): void
    {
        $this->assertSame(WmsStatus::OTHER, WmsStatusNormalizer::normalize('bukalapak', 'WEIRD_XYZ'));
    }
}
