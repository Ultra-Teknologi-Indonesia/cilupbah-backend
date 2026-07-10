<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaToInternalOrderMapper;
use Modules\Channel\Services\ShopeeToInternalOrderMapper;
use Modules\Channel\Services\TikTokToInternalOrderMapper;
use Tests\TestCase;

class CourierPickupCodeMappingTest extends TestCase
{
    public function test_shopee_pickup_code_null_when_absent(): void
    {
        $result = (new ShopeeToInternalOrderMapper())->map(
            ['order_sn' => 'SP-1', 'order_status' => 'READY_TO_SHIP'],
            'shop-1',
        );

        $this->assertArrayHasKey('pickup_code', $result);
        $this->assertNull($result['pickup_code']);
    }

    public function test_shopee_pickup_code_extracted_when_present(): void
    {
        $result = (new ShopeeToInternalOrderMapper())->map(
            ['order_sn' => 'SP-1', 'order_status' => 'READY_TO_SHIP', 'pickup_code' => '482913'],
            'shop-1',
        );

        $this->assertSame('482913', $result['pickup_code']);
    }

    public function test_tiktok_pickup_code_null_when_absent(): void
    {
        $result = (new TikTokToInternalOrderMapper())->map(
            ['id' => 'TK-1', 'status' => 'AWAITING_SHIPMENT', 'line_items' => []],
            'shop-1',
        );

        $this->assertArrayHasKey('pickup_code', $result);
        $this->assertNull($result['pickup_code']);
    }

    public function test_tiktok_pickup_code_extracted_from_package(): void
    {
        $result = (new TikTokToInternalOrderMapper())->map(
            [
                'id'         => 'TK-1',
                'status'     => 'AWAITING_SHIPMENT',
                'line_items' => [],
                'packages'   => [['pickup_code' => 'AB12CD']],
            ],
            'shop-1',
        );

        $this->assertSame('AB12CD', $result['pickup_code']);
    }

    public function test_lazada_pickup_code_null_when_absent(): void
    {
        $result = (new LazadaToInternalOrderMapper())->map(
            ['order_id' => 900123, 'statuses' => ['pending'], 'price' => '100000.00'],
            [],
            'LZ-1',
        );

        $this->assertArrayHasKey('pickup_code', $result);
        $this->assertNull($result['pickup_code']);
    }

    public function test_lazada_pickup_code_always_null(): void
    {

        $result = (new LazadaToInternalOrderMapper())->map(
            ['order_id' => 900123, 'statuses' => ['pending'], 'price' => '100000.00'],
            [['pickup_code' => 'LZ-PIN-77']],
            'LZ-1',
        );

        $this->assertArrayHasKey('pickup_code', $result);
        $this->assertNull($result['pickup_code']);
    }
}
