<?php

namespace Modules\Channel\Services;

class ShopeeEscrowMapper
{

    public function map(array $escrow): array
    {
        $income = $escrow['order_income'] ?? $escrow;

        $actualShipping = $this->num($income, 'actual_shipping_fee');
        $buyerPaidShipping = $this->num($income, 'buyer_paid_shipping_fee');
        $shopeeRebate = $this->num($income, 'shopee_shipping_rebate');

        $sellerShippingBorne = null;
        if ($actualShipping !== null) {
            $sellerShippingBorne = $actualShipping
                - ($buyerPaidShipping ?? 0)
                - ($shopeeRebate ?? 0);
        }

        $settlement = $this->num($income, 'escrow_amount');

        $paymentPromo = $this->num($income, 'payment_promotion');
        $coins = $this->num($income, 'coins');
        $ccPromo = $this->num($income, 'credit_card_promotion');
        $paymentVoucher = ($paymentPromo === null && $coins === null && $ccPromo === null)
            ? null
            : ($paymentPromo ?? 0) + ($coins ?? 0) + ($ccPromo ?? 0);

        $result = [
            'seller_voucher'           => $this->num($income, 'voucher_from_seller'),
            'platform_voucher'         => $this->num($income, 'voucher_from_shopee'),
            'payment_voucher'          => $paymentVoucher,
            'commission_fee'           => $this->num($income, 'commission_fee'),
            'service_fee'              => $this->num($income, 'service_fee'),
            'transaction_fee'          => $this->num($income, 'seller_transaction_fee'),
            'affiliate_commission'     => $this->num($income, 'order_ams_commission_fee'),
            'order_processing_fee'     => $this->num($income, 'order_processing_fee')
                ?? $this->num($income, 'order_handling_fee'),
            'seller_shipping_borne'    => $sellerShippingBorne,
            'platform_shipping_rebate' => $shopeeRebate,
            'settlement_amount'        => $settlement,
            'fee_currency'             => $escrow['currency'] ?? ($income['currency'] ?? 'IDR'),

            'is_settled'               => $settlement !== null,
        ];

        // Pajak & asuransi tak ada di Order API Shopee — hanya muncul di escrow.
        // Ditulis HANYA bila tersedia agar tidak menimpa nilai dengan null.
        $tax = $this->num($income, 'escrow_tax')
            ?? $this->sumPresent($income, ['final_product_vat_tax', 'final_shipping_vat_tax']);
        if ($tax !== null) {
            $result['total_tax'] = $tax;
        }

        $insurance = $this->num($income, 'final_product_protection')
            ?? $this->num($income, 'shipping_seller_protection_fee_amount');
        if ($insurance !== null) {
            $result['insurance_cost'] = $insurance;
        }

        $result['fee_lines'] = $this->feeLines($result, [
            'seller_voucher'           => 'voucher_from_seller',
            'platform_voucher'         => 'voucher_from_shopee',
            'payment_voucher'          => 'payment_promotion',
            'order_processing_fee'     => 'order_processing_fee',
            'commission_fee'           => 'commission_fee',
            'service_fee'              => 'service_fee',
            'transaction_fee'          => 'seller_transaction_fee',
            'affiliate_commission'     => 'order_ams_commission_fee',
            'seller_shipping_borne'    => 'actual_shipping_fee',
            'platform_shipping_rebate' => 'shopee_shipping_rebate',
            'settlement_amount'        => 'escrow_amount',
        ]);

        return $result;
    }

    private function feeLines(array $canonical, array $map): array
    {
        $lines = [];

        foreach ($map as $feeType => $channelCode) {
            if (($canonical[$feeType] ?? null) === null) {
                continue;
            }

            $lines[] = [
                'fee_type'         => $feeType,
                'channel_fee_code' => $channelCode,
                'amount'           => (float) $canonical[$feeType],
            ];
        }

        return $lines;
    }

    private function num(array $src, string $key): ?float
    {
        return isset($src[$key]) && $src[$key] !== '' ? (float) $src[$key] : null;
    }

    private function sumPresent(array $src, array $keys): ?float
    {
        $sum = null;

        foreach ($keys as $key) {
            $val = $this->num($src, $key);
            if ($val !== null) {
                $sum = ($sum ?? 0) + $val;
            }
        }

        return $sum;
    }
}
