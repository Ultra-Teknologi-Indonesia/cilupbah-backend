<?php

namespace Modules\Channel\Services;

/**
 * Memetakan response Shopee `get_escrow_detail` → struktur keuangan kanonik internal.
 *
 * Sumber: `response.order_income` (lihat Shopee Open API v2 Payment).
 * Semua fee Shopee bernilai positif (biaya). Voucher dipecah seller vs Shopee.
 */
class ShopeeEscrowMapper
{
    /**
     * @param array $escrow Isi `response` dari get_escrow_detail (memuat `order_income`).
     */
    public function map(array $escrow): array
    {
        $income = $escrow['order_income'] ?? $escrow;

        $actualShipping = $this->num($income, 'actual_shipping_fee');
        $buyerPaidShipping = $this->num($income, 'buyer_paid_shipping_fee');
        $shopeeRebate = $this->num($income, 'shopee_shipping_rebate');

        // Ongkir bersih yang ditanggung seller = ongkir aktual − dibayar buyer − subsidi Shopee.
        $sellerShippingBorne = null;
        if ($actualShipping !== null) {
            $sellerShippingBorne = $actualShipping
                - ($buyerPaidShipping ?? 0)
                - ($shopeeRebate ?? 0);
        }

        $settlement = $this->num($income, 'escrow_amount');

        $result = [
            'seller_voucher'           => $this->num($income, 'voucher_from_seller'),
            'platform_voucher'         => $this->num($income, 'voucher_from_shopee'),
            'commission_fee'           => $this->num($income, 'commission_fee'),
            'service_fee'              => $this->num($income, 'service_fee'),
            'transaction_fee'          => $this->num($income, 'seller_transaction_fee'),
            'affiliate_commission'     => $this->num($income, 'order_ams_commission_fee'),
            'seller_shipping_borne'    => $sellerShippingBorne,
            'platform_shipping_rebate' => $shopeeRebate,
            'settlement_amount'        => $settlement,
            'fee_currency'             => $escrow['currency'] ?? ($income['currency'] ?? 'IDR'),
            // Final hanya bila escrow_amount benar-benar terisi (order sudah settle).
            'is_settled'               => $settlement !== null,
        ];

        // Audit trail: tiap komponen kanonik → kode field mentah Shopee.
        $result['fee_lines'] = $this->feeLines($result, [
            'seller_voucher'           => 'voucher_from_seller',
            'platform_voucher'         => 'voucher_from_shopee',
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

    /**
     * Bentuk baris audit fee dari hasil kanonik: satu baris per komponen non-null.
     *
     * @param array<string,string> $map fee_type kanonik => kode field mentah channel
     */
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
}
