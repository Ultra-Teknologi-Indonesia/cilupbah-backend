<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\ShopeeToInternalOrderMapper;
use Modules\Outbound\Support\InstantOrderClassifier;
use Tests\TestCase;

class ShopeeInstantMappingTest extends TestCase
{
    private function order(array $overrides = []): array
    {
        return array_merge([
            'order_sn' => 'ORDER-1',
            'order_status' => 'ready_to_ship',
            'shipping_carrier' => 'J&T Express', 
        ], $overrides);
    }

    public function test_marks_instant_by_official_channel_id_even_for_non_instant_carrier_name(): void
    {
        $mapper = app(ShopeeToInternalOrderMapper::class);

        $mapped = $mapper->map($this->order(['logistics_channel_id' => 90003]), 'shop-1', ['90003']);

        $this->assertSame('INSTANT', $mapped['shipping_type']);
        $this->assertTrue(InstantOrderClassifier::isInstant($mapped['shipping_provider'], $mapped['shipping_type']));
    }

    public function test_reads_channel_id_from_package_list_fallback(): void
    {
        $mapper = app(ShopeeToInternalOrderMapper::class);

        $order = $this->order(['package_list' => [['logistics_channel_id' => 88001]]]);
        $mapped = $mapper->map($order, 'shop-1', ['88001']);

        $this->assertSame('INSTANT', $mapped['shipping_type']);
    }

    public function test_non_instant_channel_leaves_shipping_type_null_and_falls_back_to_carrier_regex(): void
    {
        $mapper = app(ShopeeToInternalOrderMapper::class);

        $regular = $mapper->map($this->order(['logistics_channel_id' => 12345]), 'shop-1', ['90003']);
        $this->assertNull($regular['shipping_type']);
        $this->assertFalse(InstantOrderClassifier::isInstant($regular['shipping_provider'], $regular['shipping_type']));

        $grab = $mapper->map($this->order(['shipping_carrier' => 'GrabExpress Instant']), 'shop-1', []);
        $this->assertNull($grab['shipping_type']);
        $this->assertTrue(InstantOrderClassifier::isInstant($grab['shipping_provider'], $grab['shipping_type']));
    }

    public function test_channel_instant_signal_is_tri_state(): void
    {
        $mapper = app(ShopeeToInternalOrderMapper::class);

        $instant = $mapper->map($this->order(['logistics_channel_id' => 90003]), 'shop-1', ['90003']);
        $this->assertTrue($instant['channel_instant']);

        $notInstant = $mapper->map($this->order(['logistics_channel_id' => 12345, 'shipping_carrier' => 'Same Day']), 'shop-1', ['90003']);
        $this->assertFalse($notInstant['channel_instant']);

        $noData = $mapper->map($this->order(['logistics_channel_id' => 12345]), 'shop-1', []);
        $this->assertNull($noData['channel_instant']);
    }

    public function test_priority_fulfillment_detected_from_carrier_name(): void
    {
        $mapper = app(ShopeeToInternalOrderMapper::class);

        $prio = $mapper->map($this->order(['shipping_carrier' => 'SPX Instant Prioritas', 'logistics_channel_id' => 90003]), 'shop-1', ['90003']);
        $this->assertTrue($prio['priority_fulfillment']);

        $plain = $mapper->map($this->order(['shipping_carrier' => 'SPX Instant', 'logistics_channel_id' => 90003]), 'shop-1', ['90003']);
        $this->assertFalse($plain['priority_fulfillment']);
    }
}
