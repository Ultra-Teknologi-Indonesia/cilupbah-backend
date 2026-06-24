<?php

namespace Modules\Channel\Services;

/**
 * Memetakan response TikTok Finance "statement transactions" → struktur keuangan
 * kanonik internal.
 *
 * CATATAN VERIFIKASI (Phase 0): nama field `fee_breakdown` TikTok dapat berbeda antar
 * rilis. Parser ini sengaja defensif — mencoba beberapa kemungkinan nama field dan
 * meng-abs() nilai fee (TikTok mengirim fee sebagai nilai negatif/pengurang) supaya
 * konsisten dengan Shopee (fee positif). Sesuaikan saat verifikasi sandbox.
 */
class TikTokStatementMapper
{
    /**
     * @param array $data Isi `data` dari getOrderStatement (memuat `statement_transactions`).
     */
    public function map(array $data): array
    {
        $transactions = $data['statement_transactions'] ?? ($data['transactions'] ?? []);

        if (empty($transactions)) {
            return ['is_settled' => false];
        }

        $settlement = 0.0;
        $commission = 0.0;
        $service = 0.0;
        $transaction = 0.0;
        $affiliate = 0.0;
        $sellerVoucher = 0.0;
        $platformVoucher = 0.0;
        $currency = 'IDR';
        $hasSettlement = false;

        foreach ($transactions as $tx) {
            $currency = $tx['currency'] ?? $currency;

            $s = $this->num($tx, ['settlement_amount', 'settlement_amount_value']);
            if ($s !== null) {
                $settlement += $s;
                $hasSettlement = true;
            }

            $breakdown = $tx['fee_breakdown'] ?? ($tx['fee_tax_breakdown'] ?? []);

            $commission += $this->abs($this->num($breakdown, ['platform_commission', 'commission', 'commission_fee']));
            $service += $this->abs($this->num($breakdown, ['sfp_service_fee', 'service_fee', 'sfp_service_fees']));
            $transaction += $this->abs($this->num($breakdown, ['transaction_fee', 'payment_fee']));
            $affiliate += $this->abs($this->num($breakdown, ['affiliate_commission', 'affiliate_partner_commission']));

            $revenue = $tx['revenue_breakdown'] ?? [];
            $sellerVoucher += $this->abs($this->num($revenue, ['seller_discount', 'seller_voucher']));
            $platformVoucher += $this->abs($this->num($revenue, ['platform_discount', 'platform_voucher']));
        }

        $result = [
            'seller_voucher'       => $sellerVoucher ?: null,
            'platform_voucher'     => $platformVoucher ?: null,
            'commission_fee'       => $commission ?: null,
            'service_fee'          => $service ?: null,
            'transaction_fee'      => $transaction ?: null,
            'affiliate_commission' => $affiliate ?: null,
            'settlement_amount'    => $hasSettlement ? $settlement : null,
            'fee_currency'         => $currency,
            'is_settled'           => $hasSettlement,
        ];

        // Audit trail: tiap komponen kanonik → nama field statement TikTok.
        $result['fee_lines'] = $this->feeLines($result, [
            'seller_voucher'       => 'seller_discount',
            'platform_voucher'     => 'platform_discount',
            'commission_fee'       => 'platform_commission',
            'service_fee'          => 'sfp_service_fee',
            'transaction_fee'      => 'transaction_fee',
            'affiliate_commission' => 'affiliate_commission',
            'settlement_amount'    => 'settlement_amount',
        ]);

        return $result;
    }

    /**
     * Bentuk baris audit fee dari hasil kanonik: satu baris per komponen non-null.
     *
     * @param array<string,string> $map fee_type kanonik => nama field statement channel
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

    /**
     * Ambil angka pertama yang ada dari beberapa kemungkinan nama field.
     */
    private function num(array $src, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($src[$key]) && $src[$key] !== '') {
                return (float) $src[$key];
            }
        }

        return null;
    }

    private function abs(?float $v): float
    {
        return $v === null ? 0.0 : abs($v);
    }
}
