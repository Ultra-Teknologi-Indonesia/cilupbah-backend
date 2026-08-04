<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\LazadaTransactionMapper;
use Tests\TestCase;

class LazadaTransactionMapperTest extends TestCase
{
    private function row(string $feeName, string $amount, array $extra = []): array
    {
        return array_merge([
            'order_no'         => '2696010908869007',
            'transaction_date' => '13 Jul 2026',
            'amount'           => $amount,
            'fee_name'         => $feeName,
            'fee_type'         => '3',
            'transaction_type' => 'Orders-Lazada Fees',
            'statement'        => '13 Jul 2026 - 13 Jul 2026',
            'WHT_amount'       => '0.00',
            'VAT_in_amount'    => '0.00',
        ], $extra);
    }

    public function test_maps_exact_fee_names_to_canonical_buckets(): void
    {
        $result = (new LazadaTransactionMapper())->map([
            $this->row('Commission', '-100.00'),
            $this->row('Payment Fee', '-55.00', ['VAT_in_amount' => '5.00']),
            $this->row('Order Processing Fee', '-30.00'),
            $this->row('SOD - COD charge', '-20.00'),
            $this->row('LazCoins Discount Promotion Fee', '-10.00'),
            $this->row('Item Price Credit', '250.00'),
        ]);

        $this->assertSame(100.0, $result['commission_fee']);
        $this->assertSame(55.0, $result['transaction_fee']);
        $this->assertSame(30.0, $result['order_processing_fee']);

        $this->assertSame(20.0, $result['service_fee']);

        $this->assertSame(10.0, $result['seller_voucher']);

        $this->assertSame(250.0, $result['gross_amount']);

        $this->assertSame('2026-07-13', $result['settled_at']->toDateString());
        $this->assertSame(5.0, $result['total_tax']);
        $this->assertTrue($result['is_settled']);
    }

    public function test_payment_fee_correction_offsets_not_adds(): void
    {
        $result = (new LazadaTransactionMapper())->map([
            $this->row('Payment Fee', '-55.00'),
            $this->row('Payment fee refund - correction for SOD-COD', '55.00'),
        ]);

        $this->assertSame(0.0, $result['transaction_fee']);
    }

    public function test_single_payment_fee_fills_transaction_fee(): void
    {
        $result = (new LazadaTransactionMapper())->map([
            $this->row('Payment Fee', '-55.00'),
        ]);

        $this->assertSame(55.0, $result['transaction_fee']);
    }

    public function test_unknown_fee_name_kept_as_other_and_counts_to_net(): void
    {
        $result = (new LazadaTransactionMapper())->map([
            $this->row('Some Brand New Fee', '-12.00'),
        ]);

        $byType = collect($result['fee_lines'])->keyBy('fee_type');
        $this->assertTrue($byType->has('other'));
        $this->assertSame('Some Brand New Fee', $byType['other']['channel_fee_code']);

        $this->assertSame(-12.0, $result['settlement_amount']);
    }

    public function test_settlement_is_sum_of_all_amounts(): void
    {
        $result = (new LazadaTransactionMapper())->map([
            $this->row('Commission', '-100.00'),
            $this->row('Item Price Credit', '250.00'),
        ]);

        $this->assertSame(150.0, $result['settlement_amount']);
    }

    public function test_amount_with_thousand_separator_is_parsed(): void
    {
        $result = (new LazadaTransactionMapper())->map([
            $this->row('Commission', '-1,250.50'),
        ]);

        $this->assertSame(1250.5, $result['commission_fee']);
    }

    public function test_settled_at_falls_back_to_statement_end(): void
    {
        $result = (new LazadaTransactionMapper())->map([
            $this->row('Commission', '-100.00', ['transaction_date' => '', 'statement' => '10 Jul 2026 - 13 Jul 2026']),
        ]);

        $this->assertSame('2026-07-13', $result['settled_at']->toDateString());
    }

    public function test_empty_is_not_settled(): void
    {
        $result = (new LazadaTransactionMapper())->map([]);

        $this->assertFalse($result['is_settled']);
    }
}
