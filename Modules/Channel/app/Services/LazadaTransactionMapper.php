<?php

namespace Modules\Channel\Services;

use Carbon\Carbon;

class LazadaTransactionMapper
{
    /**
     * Peta fee_name EKSAK (lowercase + trim) → kolom kanonik.
     * Terkonfirmasi dari data produksi region ID + docs QueryTransactionDetails.
     * Nilai 'gross' & 'refund' bukan fee potongan biasa (lihat map()).
     */
    private const FEE_MAP = [
        'commission'                                    => 'commission_fee',
        'payment fee'                                   => 'transaction_fee',
        'order processing fee'                          => 'order_processing_fee',
        'sod - cod charge'                              => 'service_fee',
        'lazcoins discount promotion fee'               => 'seller_voucher',
        // Refund/koreksi payment fee → offset transaction_fee (JAGA TANDA, jangan abs).
        'payment fee refund - correction for sod-cod'   => 'transaction_fee',

        // dari docs QueryTransactionDetails:
        'shipping fee'                                  => 'seller_shipping_borne',
        'shipping fee paid by seller'                   => 'seller_shipping_borne',
        'reverse shipping fee'                          => 'seller_shipping_borne',
        'voucher'                                       => 'seller_voucher',
        'seller voucher'                                => 'seller_voucher',
        'promotional charges'                           => 'seller_voucher',
        'shipping fee voucher'                          => 'platform_shipping_rebate',
        'shipping subsidy'                              => 'platform_shipping_rebate',
    ];

    // fee_name yang berkontribusi ke gross (kredit harga item), BUKAN fee potongan.
    private const GROSS_NAMES = ['item price credit'];

    // fee_name yang merupakan refund yang mengurangi settlement.
    private const REFUND_NAMES = ['refund', 'item price credit refund'];

    public function map(array $transactions): array
    {
        if (empty($transactions)) {
            return ['is_settled' => false, 'raw' => $transactions];
        }

        $settlement = 0.0;
        $totalTax = 0.0;
        $grossAmount = 0.0;
        $refundTotal = 0.0;

        // Kolom kanonik: TANDA disimpan sesuai peran (refund/koreksi bisa positif offset).
        $buckets = [
            'seller_voucher'           => 0.0,
            'platform_voucher'         => 0.0,
            'payment_voucher'          => 0.0,
            'commission_fee'           => 0.0,
            'service_fee'              => 0.0,
            'transaction_fee'          => 0.0,
            'affiliate_commission'     => 0.0,
            'order_processing_fee'     => 0.0,
            'seller_shipping_borne'    => 0.0,
            'platform_shipping_rebate' => 0.0,
        ];
        $touched = [];

        $feeLines = [];
        $settledAt = null;

        foreach ($transactions as $tx) {
            $amount = $this->amount($tx['amount'] ?? null);
            if ($amount === null) {
                continue;
            }

            // Σ amount = net settlement (SEMUA baris berkontribusi — sudah benar).
            $settlement += $amount;

            $totalTax += ($this->amount($tx['VAT_in_amount'] ?? null) ?? 0.0);
            $totalTax += ($this->amount($tx['WHT_amount'] ?? null) ?? 0.0);

            $settledAt = $settledAt ?? $this->parseSettledAt($tx);

            $feeNameRaw = trim((string) ($tx['fee_name'] ?? ''));
            $feeName = strtolower($feeNameRaw);

            if ($feeName === '') {
                continue;
            }

            // Kredit harga item → gross, bukan fee_line potongan.
            if (in_array($feeName, self::GROSS_NAMES, true)) {
                $grossAmount += $amount;
                continue;
            }

            // Refund eksplisit yang mengurangi settlement.
            if (in_array($feeName, self::REFUND_NAMES, true)) {
                $refundTotal += abs($amount);
                $feeLines[] = $this->line('refund_total', $feeNameRaw, $amount);
                continue;
            }

            $feeType = self::FEE_MAP[$feeName] ?? null;

            if ($feeType === null) {
                // fee tak dikenal → tetap dicatat sebagai `other` (tak dibuang, tetap masuk net).
                $feeLines[] = $this->line('other', $feeNameRaw, $amount);
                continue;
            }

            // Akumulasi BER-TANDA agar refund/koreksi meng-OFFSET (bukan menambah magnitudo).
            // Kolom kanonik = abs(net) di akhir; fee_line simpan amount ber-tanda.
            $buckets[$feeType] += $amount;
            $touched[$feeType] = true;
            $feeLines[] = $this->line($feeType, $feeNameRaw, $amount);
        }

        $result = [
            'seller_voucher'           => $this->nullable($buckets, $touched, 'seller_voucher'),
            'platform_voucher'         => $this->nullable($buckets, $touched, 'platform_voucher'),
            'payment_voucher'          => $this->nullable($buckets, $touched, 'payment_voucher'),
            'commission_fee'           => $this->nullable($buckets, $touched, 'commission_fee'),
            'service_fee'              => $this->nullable($buckets, $touched, 'service_fee'),
            'transaction_fee'          => $this->nullable($buckets, $touched, 'transaction_fee'),
            'affiliate_commission'     => $this->nullable($buckets, $touched, 'affiliate_commission'),
            'order_processing_fee'     => $this->nullable($buckets, $touched, 'order_processing_fee'),
            'seller_shipping_borne'    => $this->nullable($buckets, $touched, 'seller_shipping_borne'),
            'platform_shipping_rebate' => $this->nullable($buckets, $touched, 'platform_shipping_rebate'),
            'settlement_amount'        => $settlement,
            'refund_total'             => $refundTotal ?: null,
            'gross_amount'             => $grossAmount ?: null,
            'total_tax'                => $totalTax ?: null,
            'fee_currency'             => 'IDR',
            'settled_at'               => $settledAt,
            'is_settled'               => true,
            'fee_lines'                => $feeLines,
            'raw'                      => $transactions,
        ];

        return $result;
    }

    private function nullable(array $buckets, array $touched, string $key): ?float
    {
        // Magnitudo kolom kanonik = abs(net ber-tanda).
        return isset($touched[$key]) ? abs($buckets[$key]) : null;
    }

    private function line(string $feeType, string $channelCode, float $amount): array
    {
        return [
            'fee_type'         => $feeType,
            'channel_fee_code' => $channelCode,
            'amount'           => $amount, // BER-TANDA
        ];
    }

    /**
     * settled_at dari transaction_date ("13 Jul 2026") atau ujung `statement`
     * ("13 Jul 2026 - 13 Jul 2026").
     */
    private function parseSettledAt(array $tx): ?Carbon
    {
        $date = trim((string) ($tx['transaction_date'] ?? ''));

        if ($date === '' && ! empty($tx['statement'])) {
            $parts = explode('-', (string) $tx['statement']);
            $date = trim(end($parts));
        }

        if ($date === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d M Y', $date)->startOfDay();
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($date);
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }

    private function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '', (string) $value);
    }
}
