<?php

namespace Modules\Channel\Services;

class TikTokStatementMapper
{
    private const SERVICE_FEE_KEYS = [
        'sfp_service_fee_amount',
        'mall_service_fee_amount',
        'voucher_xtra_service_fee_amount',
        'flash_sales_service_fee_amount',
        'cofunded_promotion_service_fee_amount',
        'live_specials_fee_amount',
        'pre_order_service_fee_amount',
        'seller_growth_fee_amount',
        'bonus_cashback_service_fee_amount',
    ];

    private const AFFILIATE_KEYS = [
        'affiliate_commission_amount',
        'affiliate_partner_commission_amount',
        'affiliate_ads_commission_amount',
    ];

    private const TAX_KEYS = [
        'local_vat_amount',
        'pit_amount',
        'vat_amount',
        'import_vat_amount',
        'sst_amount',
        'gst_amount',
    ];

    public function map(array $data): array
    {
        $skuTransactions = $data['sku_transactions'] ?? null;

        if ($skuTransactions === null) {
            $hasInlineBreakdown = isset($data['revenue_breakdown']) || isset($data['fee_tax_breakdown']);
            $skuTransactions = $hasInlineBreakdown ? [$data] : [];
        }

        $orderSettlement = $this->num($data, 'settlement_amount');
        $revenueAmount = $data['revenue_amount'] ?? null;

        if (empty($skuTransactions) && ($orderSettlement === null || $orderSettlement == 0.0)) {
            return ['is_settled' => false, 'raw' => $data];
        }

        $gross = 0.0;
        $sellerVoucher = 0.0;
        $refund = 0.0;
        $commission = 0.0;
        $service = 0.0;
        $transaction = 0.0;
        $affiliate = 0.0;
        $processing = 0.0;
        $totalTax = 0.0;

        foreach ($skuTransactions as $sku) {
            $revenue = $sku['revenue_breakdown'] ?? [];
            $gross += $this->abs($this->num($revenue, 'subtotal_before_discount_amount'));
            $sellerVoucher += $this->abs($this->num($revenue, 'seller_discount_amount'));
            $refund += $this->abs($this->num($revenue, 'refund_subtotal_before_discount_amount'))
                + $this->abs($this->num($revenue, 'seller_discount_refund_amount'));

            $feeTax = $sku['fee_tax_breakdown'] ?? [];
            $fee = $feeTax['fee'] ?? [];
            $tax = $feeTax['tax'] ?? [];

            $commission += $this->abs($this->num($fee, 'platform_commission_amount'))
                + $this->abs($this->num($fee, 'dynamic_commission_amount')) 
                + $this->abs($this->num($fee, 'referral_fee_amount'));

            $service += $this->sumAbs($fee, self::SERVICE_FEE_KEYS);

            $transaction += $this->abs($this->num($fee, 'transaction_fee_amount'))
                + $this->abs($this->num($fee, 'credit_card_handling_fee_amount'));

            $affiliate += $this->sumAbs($fee, self::AFFILIATE_KEYS);

            $processing += $this->abs($this->num($fee, 'dt_handling_fee_amount'))
                + $this->abs($this->num($fee, 'seller_paylater_handling_fee_amount'));

            $totalTax += $this->sumAbs($tax, self::TAX_KEYS);
        }

        $shippingCost = $this->num($data, 'shipping_cost_amount');
        $sellerShippingBorne = ($shippingCost !== null && $shippingCost < 0) ? abs($shippingCost) : null;

        $shippingBreakdown = $data['shipping_cost_breakdown'] ?? [];
        $platformShippingRebate = $this->abs($this->num($shippingBreakdown, 'shipping_fee_discount_amount')) ?: null;

        $isSettled = ($orderSettlement !== null && (string) $revenueAmount !== '0')
            || ! empty($data['sku_transactions']);

        $result = [
            'seller_voucher'           => $sellerVoucher ?: null,
            'platform_voucher'         => null,
            'payment_voucher'          => null,
            'commission_fee'           => $commission ?: null,
            'service_fee'              => $service ?: null,
            'transaction_fee'          => $transaction ?: null,
            'affiliate_commission'     => $affiliate ?: null,
            'order_processing_fee'     => $processing ?: null,
            'seller_shipping_borne'    => $sellerShippingBorne,
            'platform_shipping_rebate' => $platformShippingRebate,
            'settlement_amount'        => $orderSettlement,
            'refund_total'             => $refund ?: null,
            'gross_amount'             => $gross ?: null,
            'total_tax'                => $totalTax ?: null,
            'fee_currency'             => $data['currency'] ?? 'IDR',
            'settled_at'               => null, 
            'is_settled'               => $isSettled,
            'raw'                      => $data,
        ];

        $result['fee_lines'] = $this->feeLines($result, [
            'seller_voucher'           => 'seller_discount_amount',
            'commission_fee'           => 'platform_commission_amount',
            'service_fee'              => 'sfp_service_fee_amount',
            'transaction_fee'          => 'transaction_fee_amount',
            'affiliate_commission'     => 'affiliate_commission_amount',
            'order_processing_fee'     => 'dt_handling_fee_amount',
            'seller_shipping_borne'    => 'shipping_cost_amount',
            'platform_shipping_rebate' => 'shipping_fee_discount_amount',
        ]);

        return $result;
    }

    private function feeLines(array $canonical, array $map): array
    {
        $lines = [];

        foreach ($map as $feeType => $channelCode) {
            $value = $canonical[$feeType] ?? null;
            if ($value === null) {
                continue;
            }

            $signed = in_array($feeType, ['platform_shipping_rebate'], true)
                ? (float) $value
                : -abs((float) $value);

            $lines[] = [
                'fee_type'         => $feeType,
                'channel_fee_code' => $channelCode,
                'amount'           => $signed,
            ];
        }

        return $lines;
    }

    private function sumAbs(array $src, array $keys): float
    {
        $sum = 0.0;
        foreach ($keys as $key) {
            $sum += $this->abs($this->num($src, $key));
        }

        return $sum;
    }

    private function num(array $src, string $key): ?float
    {
        return isset($src[$key]) && $src[$key] !== '' ? (float) $src[$key] : null;
    }

    private function abs(?float $v): float
    {
        return $v === null ? 0.0 : abs($v);
    }
}
