<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Enums\ChannelStatus;
use Modules\Sales\Support\ChannelStatusNormalizer;
use PHPUnit\Framework\TestCase;

class ChannelStatusNormalizerTest extends TestCase
{
    public function test_null_returns_null(): void
    {
        $this->assertNull(ChannelStatusNormalizer::normalize('shopee', null));
        $this->assertNull(ChannelStatusNormalizer::normalize('shopee', ''));
    }

    public function test_canonical_value_fastpath(): void
    {
        $this->assertSame(
            ChannelStatus::SHIPPED,
            ChannelStatusNormalizer::normalize('anything', 'SHIPPED'),
        );
    }

    public function test_shopee_specific_codes(): void
    {
        $cases = [
            'UNPAID'             => ChannelStatus::UNPAID,
            'READY_TO_SHIP'      => ChannelStatus::READY_TO_SHIP,
            'PROCESSED'          => ChannelStatus::PROCESSED,
            'RETRY_SHIP'         => ChannelStatus::PROCESSED,
            'SHIPPED'            => ChannelStatus::SHIPPED,
            'IN_CANCEL'          => ChannelStatus::IN_CANCEL,
            'CANCELLED'          => ChannelStatus::CANCELLED,
            'TO_RETURN'          => ChannelStatus::RETURN_REQUESTED,
            'TO_CONFIRM_RECEIVE' => ChannelStatus::TO_CONFIRM_RECEIVE,
            'COMPLETED'          => ChannelStatus::COMPLETED,
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame(
                $expected,
                ChannelStatusNormalizer::normalize('shopee', $raw),
                "Shopee code {$raw} harus jadi {$expected->value}",
            );
        }
    }

    public function test_tiktok_specific_codes(): void
    {
        $this->assertSame(ChannelStatus::UNPAID, ChannelStatusNormalizer::normalize('tiktok', 'ON_HOLD'));
        $this->assertSame(ChannelStatus::READY_TO_SHIP, ChannelStatusNormalizer::normalize('tiktok', 'AWAITING_SHIPMENT'));
        $this->assertSame(ChannelStatus::PROCESSED, ChannelStatusNormalizer::normalize('tiktok', 'AWAITING_COLLECTION'));
        $this->assertSame(ChannelStatus::SHIPPED, ChannelStatusNormalizer::normalize('tiktok', 'IN_TRANSIT'));
        $this->assertSame(ChannelStatus::TO_CONFIRM_RECEIVE, ChannelStatusNormalizer::normalize('tiktok', 'DELIVERED'));
    }

    public function test_lazada_lowercase_codes(): void
    {
        $this->assertSame(ChannelStatus::UNPAID, ChannelStatusNormalizer::normalize('lazada', 'pending'));
        $this->assertSame(ChannelStatus::READY_TO_SHIP, ChannelStatusNormalizer::normalize('lazada', 'ready_to_ship'));
        $this->assertSame(ChannelStatus::SHIPPED, ChannelStatusNormalizer::normalize('lazada', 'shipped'));
        $this->assertSame(ChannelStatus::TO_CONFIRM_RECEIVE, ChannelStatusNormalizer::normalize('lazada', 'delivered'));
        $this->assertSame(ChannelStatus::RETURNED, ChannelStatusNormalizer::normalize('lazada', 'returned'));
    }

    public function test_woocommerce_specific_codes(): void
    {
        $this->assertSame(ChannelStatus::UNPAID, ChannelStatusNormalizer::normalize('woocommerce', 'pending'));
        $this->assertSame(ChannelStatus::PROCESSED, ChannelStatusNormalizer::normalize('woocommerce', 'processing'));
        $this->assertSame(ChannelStatus::COMPLETED, ChannelStatusNormalizer::normalize('woocommerce', 'completed'));
        $this->assertSame(ChannelStatus::RETURNED, ChannelStatusNormalizer::normalize('woocommerce', 'refunded'));
    }

    public function test_unknown_channel_returns_unknown(): void
    {
        $this->assertSame(
            ChannelStatus::UNKNOWN,
            ChannelStatusNormalizer::normalize('bukalapak', 'PROCESSED_ORDER'),
        );
    }

    public function test_unknown_code_within_known_channel_returns_unknown(): void
    {
        $this->assertSame(
            ChannelStatus::UNKNOWN,
            ChannelStatusNormalizer::normalize('shopee', 'BUYER_HELD_ORDER_XYZ'),
        );
    }

    public function test_case_insensitive_fallback(): void
    {
        // Kode Shopee kadang datang lowercase dari webhook lain
        $this->assertSame(ChannelStatus::SHIPPED, ChannelStatusNormalizer::normalize('shopee', 'shipped'));
        $this->assertSame(ChannelStatus::CANCELLED, ChannelStatusNormalizer::normalize('shopee', 'cancelled'));
    }
}
