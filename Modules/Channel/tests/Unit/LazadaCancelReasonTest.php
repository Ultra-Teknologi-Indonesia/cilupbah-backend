<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaToInternalOrderMapper;
use PHPUnit\Framework\TestCase;

/**
 * Lazada cancellations must capture cancel_reason (consistent with TikTok),
 * not hardcode null.
 */
class LazadaCancelReasonTest extends TestCase
{
    private function items(array $overrides = []): array
    {
        return [array_merge([
            'sku'        => 'SKU-X',
            'item_price' => 1000,
            'paid_price' => 1000,
            'name'       => 'Item',
        ], $overrides)];
    }

    public function test_cancelled_order_captures_reason_from_payload(): void
    {
        $mapper = new LazadaToInternalOrderMapper();

        $result = $mapper->map(
            ['order_id' => 'LZ-1', 'statuses' => ['canceled'], 'reason' => 'Buyer changed mind'],
            $this->items(),
            'SHOP-1'
        );

        $this->assertTrue($result['is_canceled']);
        $this->assertSame('CANCELLED', $result['channel_status']);
        $this->assertSame('Buyer changed mind', $result['cancel_reason']);
    }

    public function test_cancelled_order_falls_back_to_item_reason(): void
    {
        $mapper = new LazadaToInternalOrderMapper();

        $result = $mapper->map(
            ['order_id' => 'LZ-2', 'statuses' => ['canceled']],
            $this->items(['reason' => 'Out of stock']),
            'SHOP-1'
        );

        $this->assertSame('Out of stock', $result['cancel_reason']);
    }

    public function test_non_cancelled_order_has_null_reason(): void
    {
        $mapper = new LazadaToInternalOrderMapper();

        $result = $mapper->map(
            ['order_id' => 'LZ-3', 'statuses' => ['pending'], 'reason' => 'ignored'],
            $this->items(),
            'SHOP-1'
        );

        $this->assertFalse($result['is_canceled']);
        $this->assertNull($result['cancel_reason']);
    }
}
