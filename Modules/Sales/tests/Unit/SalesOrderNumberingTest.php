<?php

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class SalesOrderNumberingTest extends TestCase
{
    public function test_tokopedia_order_uses_tp_prefix_when_received_through_tiktok_provider(): void
    {
        $numbering = app(SalesOrderService::class)->generateSalesOrderNo(
            'tiktok',
            '585727997337830752',
            'TOKOPEDIA',
        );

        $this->assertSame('TP-585727997337830752', $numbering['salesorder_no']);
        $this->assertSame('585727997337830752', $numbering['channel_order_no']);
    }

    public function test_tiktok_shop_order_keeps_tt_prefix(): void
    {
        $numbering = app(SalesOrderService::class)->generateSalesOrderNo(
            'tiktok',
            '585727997337830753',
            'TIKTOK_SHOP',
        );

        $this->assertSame('TT-585727997337830753', $numbering['salesorder_no']);
    }
}
