<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaToInternalOrderMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LazadaToInternalOrderMapperStatusTest extends TestCase
{
    private function mapWithStatus(string $status): array
    {
        $mapper = new LazadaToInternalOrderMapper;

        return $mapper->map([
            'order_id' => 900123,
            'statuses' => [$status],
            'price' => '100000.00',
            'created_at' => '2026-06-10 09:00:00 +0700',
        ], [], 'LZ-100');
    }

    public static function statusProvider(): array
    {
        return [
            'repacked' => ['repacked', 'READY_TO_SHIP'],
            'returned' => ['returned', 'RETURNED'],
            'failed_delivery' => ['failed_delivery', 'SHIPPED'],
            'shipped_back' => ['shipped_back', 'RETURNED'],
            'shipped_back_success' => ['shipped_back_success', 'RETURNED'],
            'shipped_back_failed' => ['shipped_back_failed', 'SHIPPED'],
            'lost_by_3pl' => ['lost_by_3pl', 'SHIPPED'],
            'damaged_by_3pl' => ['damaged_by_3pl', 'SHIPPED'],
            'package_scrapped' => ['package_scrapped', 'SHIPPED'],
            'package_returned' => ['package_returned', 'RETURNED'],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_new_statuses_no_longer_fall_back_to_unpaid(string $lazadaStatus, string $expectedChannelStatus): void
    {
        $internal = $this->mapWithStatus($lazadaStatus);

        $this->assertSame($expectedChannelStatus, $internal['channel_status']);
        $this->assertNotSame('UNPAID', $internal['channel_status']);
    }

    public function test_unknown_status_is_passed_through_visibly_not_silently_unpaid(): void
    {
        $internal = $this->mapWithStatus('some_future_lazada_status');

        $this->assertSame('SOME_FUTURE_LAZADA_STATUS', $internal['channel_status']);
        $this->assertNotSame('UNPAID', $internal['channel_status']);
    }

    public function test_reverse_logistics_statuses_are_not_treated_as_cancelled(): void
    {
        foreach (['returned', 'failed_delivery', 'shipped_back', 'lost_by_3pl', 'damaged_by_3pl'] as $status) {
            $internal = $this->mapWithStatus($status);
            $this->assertFalse($internal['is_canceled'], "status {$status} tidak boleh dianggap CANCELLED");
        }
    }

    public function test_cancel_by_reads_item_cancel_return_initiator(): void
    {
        $internal = (new LazadaToInternalOrderMapper)->map(
            ['order_id' => 900200, 'statuses' => ['canceled'], 'price' => '23500.00', 'created_at' => '2026-08-03 23:53:51 +0700'],
            [['sku' => 'X', 'paid_price' => '23500', 'cancel_return_initiator' => 'buyer-cancel', 'reason' => 'Tidak ingin pesanan ini lagi']],
            'LZ-100'
        );

        $this->assertTrue($internal['is_canceled']);
        $this->assertSame('buyer-cancel', $internal['cancel_by']);
        $this->assertSame('Tidak ingin pesanan ini lagi', $internal['cancel_reason']);
    }

    public function test_cancel_by_captured_for_returned_orders_too(): void
    {
        $internal = (new LazadaToInternalOrderMapper)->map(
            ['order_id' => 900300, 'statuses' => ['returned'], 'price' => '30000.00'],
            [['sku' => 'X', 'paid_price' => '30000', 'cancel_return_initiator' => 'buyer-return']],
            'LZ-100'
        );

        $this->assertSame('RETURNED', $internal['channel_status']);
        $this->assertSame('buyer-return', $internal['cancel_by']);
    }

    public function test_shipping_cost_is_buyer_paid_not_gross_logistics(): void
    {

        $free = (new LazadaToInternalOrderMapper)->map(
            ['order_id' => 1, 'statuses' => ['delivered'], 'price' => '23500.00', 'shipping_fee' => 107000],
            [['sku' => 'A', 'paid_price' => '23500']],
            'LZ-100'
        );
        $this->assertSame(0.0, $free['shipping_cost']);
        $this->assertSame(23500.0, $free['grand_total']);

        $paid = (new LazadaToInternalOrderMapper)->map(
            ['order_id' => 2, 'statuses' => ['delivered'], 'price' => '25000.00', 'shipping_fee' => 5000],
            [['sku' => 'A', 'paid_price' => '20000']],
            'LZ-100'
        );
        $this->assertSame(5000.0, $paid['shipping_cost']);
        $this->assertSame(25000.0, $paid['grand_total']);
    }

    public function test_reads_instant_from_lazada_channel_shipping_type(): void
    {
        $internal = (new LazadaToInternalOrderMapper)->map(
            [
                'order_id' => 900400,
                'statuses' => ['ready_to_ship'],
                'shipping_type' => 'SAME_DAY',
                'price' => '30000.00',
            ],
            [],
            'LZ-100',
        );

        $this->assertTrue($internal['channel_instant']);
        $this->assertSame('SAME_DAY', $internal['shipping_type']);
    }

    public function test_prefers_explicit_delivery_category_over_dropshipping_fulfillment_type(): void
    {
        $internal = (new LazadaToInternalOrderMapper)->map(
            [
                'order_id' => 900401,
                'statuses' => ['ready_to_ship'],
                'shipping_type' => 'Dropshipping',
                'delivery_type' => 'same_day',
                'price' => '30000.00',
            ],
            [],
            'LZ-100',
        );

        $this->assertSame('SAME_DAY', $internal['shipping_type']);
        $this->assertTrue($internal['channel_instant']);
    }

    public function test_reads_lazada_shipping_provider_type_from_order_item(): void
    {
        $internal = (new LazadaToInternalOrderMapper)->map(
            [
                'order_id' => 900404,
                'statuses' => ['ready_to_ship'],
                'shipping_type' => 'Dropshipping',
                'price' => '30000.00',
            ],
            [['shipping_provider_type' => 'standard']],
            'LZ-100',
        );

        $this->assertSame('STANDARD', $internal['shipping_type']);
        $this->assertFalse($internal['channel_instant']);
    }

    public function test_provider_name_alone_does_not_classify_lazada_order_as_instant(): void
    {
        $internal = (new LazadaToInternalOrderMapper)->map(
            [
                'order_id' => 900402,
                'statuses' => ['ready_to_ship'],
                'price' => '30000.00',
            ],
            [['shipment_provider' => 'Grab-ID']],
            'LZ-100',
        );

        $this->assertNull($internal['channel_instant']);
        $this->assertNull($internal['shipping_type']);
    }

    public function test_captures_channel_shipping_service_id_without_using_provider_name(): void
    {
        $internal = (new LazadaToInternalOrderMapper)->map(
            [
                'order_id' => 900403,
                'statuses' => ['ready_to_ship'],
                'delivery_option_id' => 'LZD-SERVICE-1',
                'price' => '30000.00',
            ],
            [],
            'LZ-100',
        );

        $this->assertSame('LZD-SERVICE-1', $internal['delivery_option_id']);
    }
}
