<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaToInternalOrderMapper;
use Modules\Channel\Services\ShopeeToInternalOrderMapper;
use Modules\Channel\Services\TikTokToInternalOrderMapper;
use Tests\TestCase;

/**
 * Fase 2 (Bukti Pickup Kurir) — memastikan emit-point `pickup_code` di tiap
 * mapper channel bekerja: null bila field tidak ada (kondisi nyata sekarang,
 * karena tidak ada field kode pengambilan yang terkonfirmasi di API), dan
 * terisi begitu field yang plausible muncul di payload. Ini membuktikan pipeline
 * end-to-end siap: begitu field asli dikonfirmasi, cukup sesuaikan helper mapper.
 * Lihat PLANNING-BUKTI-PICKUP-KURIR.md §7.
 */
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
        // Terkonfirmasi: Lazada B2C tidak punya kode pengambilan kurir (pickup_order_no
        // yang ada = JIT, bukan ini). Sengaja selalu null meski ada key nyasar.
        $result = (new LazadaToInternalOrderMapper())->map(
            ['order_id' => 900123, 'statuses' => ['pending'], 'price' => '100000.00'],
            [['pickup_code' => 'LZ-PIN-77']],
            'LZ-1',
        );

        $this->assertArrayHasKey('pickup_code', $result);
        $this->assertNull($result['pickup_code']);
    }
}
