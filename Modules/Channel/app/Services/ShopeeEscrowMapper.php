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

        return [
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
    }

    private function num(array $src, string $key): ?float
    {
        return isset($src[$key]) && $src[$key] !== '' ? (float) $src[$key] : null;
    }
}
