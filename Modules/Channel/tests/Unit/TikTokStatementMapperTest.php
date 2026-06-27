<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\TikTokStatementMapper;
use Tests\TestCase;

class TikTokStatementMapperTest extends TestCase
{
    public function test_maps_statement_and_makes_fees_positive(): void
    {
        $result = (new TikTokStatementMapper())->map([
            'statement_transactions' => [[
                'currency'          => 'IDR',
                'settlement_amount' => 90000,
                'fee_breakdown'     => [
                    'platform_commission' => -2200,
                    'sfp_service_fee'     => -1100,
                    'transaction_fee'     => -800,
                    'affiliate_commission' => -500,
                ],
                'revenue_breakdown' => [
                    'seller_discount'   => 5000,
                    'platform_discount' => 3000,
                ],
            ]],
        ]);

        $this->assertSame(2200.0, $result['commission_fee']);
        $this->assertSame(1100.0, $result['service_fee']);
        $this->assertSame(800.0, $result['transaction_fee']);
        $this->assertSame(500.0, $result['affiliate_commission']);
        $this->assertSame(5000.0, $result['seller_voucher']);
        $this->assertSame(3000.0, $result['platform_voucher']);
        $this->assertSame(90000.0, $result['settlement_amount']);
        $this->assertTrue($result['is_settled']);
    }

    public function test_aggregates_multiple_transactions(): void
    {
        $result = (new TikTokStatementMapper())->map([
            'statement_transactions' => [
                ['settlement_amount' => 40000, 'fee_breakdown' => ['platform_commission' => -1000]],
                ['settlement_amount' => 50000, 'fee_breakdown' => ['platform_commission' => -1500]],
            ],
        ]);

        $this->assertSame(2500.0, $result['commission_fee']);
        $this->assertSame(90000.0, $result['settlement_amount']);
    }

    public function test_empty_statement_is_not_settled(): void
    {
        $result = (new TikTokStatementMapper())->map([]);

        $this->assertFalse($result['is_settled']);
    }

    public function test_fee_lines_use_statement_field_names(): void
    {
        $result = (new TikTokStatementMapper())->map([
            'statement_transactions' => [[
                'settlement_amount' => 1000,
                'fee_breakdown'     => ['platform_commission' => -2200],
            ]],
        ]);

        $byType = collect($result['fee_lines'])->keyBy('fee_type');

        $this->assertSame('platform_commission', $byType['commission_fee']['channel_fee_code']);
        $this->assertSame(2200.0, $byType['commission_fee']['amount']);
    }
}
