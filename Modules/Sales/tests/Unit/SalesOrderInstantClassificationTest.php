<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class SalesOrderInstantClassificationTest extends TestCase
{
    public function test_channel_false_overrides_instant_like_provider_name(): void
    {
        $order = new SalesOrder([
            'channel_instant' => false,
            'resolved_shipment_type' => 'REGULAR',
            'shipping_provider' => 'SPX Sameday',
            'shipping_type' => null,
        ]);

        $this->assertFalse($order->is_instant);
    }

    public function test_channel_true_marks_instant_even_when_provider_name_is_generic(): void
    {
        $order = new SalesOrder([
            'channel_instant' => true,
            'resolved_shipment_type' => 'REGULAR',
            'shipping_provider' => 'J&T Express',
            'shipping_type' => 'TIKTOK',
        ]);

        $this->assertTrue($order->is_instant);
    }

    public function test_legacy_resolved_type_is_used_when_channel_signal_is_unknown(): void
    {
        $order = new SalesOrder([
            'channel_instant' => null,
            'resolved_shipment_type' => 'INSTANT',
            'shipping_provider' => 'Generic Courier',
        ]);

        $this->assertTrue($order->is_instant);
    }

    public function test_marketplace_order_with_stale_legacy_instant_type_is_not_instant_without_channel_category(): void
    {
        $order = new SalesOrder([
            'source' => 'shopee',
            'channel_instant' => null,
            'resolved_shipment_type' => 'INSTANT',
            'shipping_provider' => 'SPX Same Day',
        ]);

        $this->assertFalse($order->is_instant);
    }

    public function test_channel_order_does_not_fallback_to_provider_name_without_channel_category(): void
    {
        $order = new SalesOrder([
            'source' => 'tiktok',
            'channel_instant' => null,
            'resolved_shipment_type' => null,
            'shipping_provider' => 'Grab Instant Hemat',
            'shipping_type' => 'TIKTOK',
        ]);

        $this->assertFalse($order->is_instant);
    }
}
