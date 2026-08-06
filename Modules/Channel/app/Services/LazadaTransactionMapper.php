<?php

namespace Modules\Channel\Services;

use Carbon\Carbon;

class LazadaTransactionMapper
{

    private const FEE_MAP = [
        'commission'                                    => 'commission_fee',
        'payment fee'                                   => 'transaction_fee',
        'order processing fee'                          => 'order_processing_fee',
        'sod - cod charge'                              => 'service_fee',
        'lazcoins discount promotion fee'               => 'seller_voucher',
        'lazcoins discount'                             => 'seller_voucher',

        'payment fee refund - correction for sod-cod'   => 'transaction_fee',

        'shipping fee'                                  => 'seller_shipping_borne',
        'shipping fee paid by seller'                   => 'seller_shipping_borne',
        'reverse shipping fee'                          => 'seller_shipping_borne',
        'free shipping max fee'                         => 'seller_shipping_borne',
        'voucher'                                       => 'seller_voucher',
        'seller voucher'                                => 'seller_voucher',
        'promotional charges'                           => 'seller_voucher',
        'shipping fee voucher'                          => 'platform_shipping_rebate',
        'shipping subsidy'                              => 'platform_shipping_rebate',
    ];

    private const GROSS_NAMES = ['item price credit'];

    private const REFUND_NAMES = ['refund', 'item price credit refund', 'reversal item price'];

    public function map(array $transactions): array
    {
        if (empty($transactions)) {
            return ['is_settled' => false, 'raw' => $transactions];
        }

        $settlement = 0.0;
        $totalTax = 0.0;
        $grossAmount = 0.0;
        $refundTotal = 0.0;

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

            $settlement += $amount;

            $totalTax += ($this->amount($tx['VAT_in_amount'] ?? null) ?? 0.0);
            $totalTax += ($this->amount($tx['WHT_amount'] ?? null) ?? 0.0);

            $settledAt = $settledAt ?? $this->parseSettledAt($tx);

            $feeNameRaw = trim((string) ($tx['fee_name'] ?? ''));
            $feeName = strtolower($feeNameRaw);

            if ($feeName === '') {
                continue;
            }

            if (in_array($feeName, self::GROSS_NAMES, true)) {
                $grossAmount += $amount;
                continue;
            }

            if (in_array($feeName, self::REFUND_NAMES, true)) {
                $refundTotal += abs($amount);
                $feeLines[] = $this->line('refund_total', $feeNameRaw, $amount);
                continue;
            }

            $feeType = self::FEE_MAP[$feeName] ?? null;

            if ($feeType === null && str_starts_with($feeName, 'reversal ')) {
                $feeType = self::FEE_MAP[substr($feeName, strlen('reversal '))] ?? null;
            }

            if ($feeType === null) {

                $feeLines[] = $this->line('other', $feeNameRaw, $amount);
                continue;
            }

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

        return isset($touched[$key]) ? abs($buckets[$key]) : null;
    }

    private function line(string $feeType, string $channelCode, float $amount): array
    {
        return [
            'fee_type'         => $feeType,
            'channel_fee_code' => $channelCode,
            'amount'           => $amount, 
        ];
    }

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
