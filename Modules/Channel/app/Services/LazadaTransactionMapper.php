<?php

namespace Modules\Channel\Services;

class LazadaTransactionMapper
{

    public function map(array $transactions): array
    {
        if (empty($transactions)) {
            return ['is_settled' => false];
        }

        $settlement = 0.0;
        $commission = 0.0;
        $service = 0.0;
        $transaction = 0.0;
        $processing = 0.0;
        $sellerVoucher = 0.0;
        $platformShippingRebate = 0.0;

        foreach ($transactions as $tx) {
            $amount = $this->amount($tx['amount'] ?? null);
            if ($amount === null) {
                continue;
            }

            // Net payout = jumlah semua baris statement (fee bernilai negatif).
            $settlement += $amount;

            $feeName = strtolower((string) ($tx['fee_name'] ?? ''));

            if (str_contains($feeName, 'commission')) {
                $commission += abs($amount);
            } elseif (str_contains($feeName, 'payment fee')) {
                $transaction += abs($amount);
            } elseif (str_contains($feeName, 'order fulfillment') || str_contains($feeName, 'fulfillment fee') || str_contains($feeName, 'processing')) {
                $processing += abs($amount);
            } elseif (str_contains($feeName, 'shipping fee voucher') || str_contains($feeName, 'shipping subsid')) {
                $platformShippingRebate += abs($amount);
            } elseif (str_contains($feeName, 'promotional charge') || str_contains($feeName, 'voucher')) {
                $sellerVoucher += abs($amount);
            } elseif (str_contains($feeName, 'service fee') || str_contains($feeName, 'value added')) {
                $service += abs($amount);
            }
        }

        $result = [
            'seller_voucher'           => $sellerVoucher ?: null,
            'commission_fee'           => $commission ?: null,
            'service_fee'              => $service ?: null,
            'transaction_fee'          => $transaction ?: null,
            'order_processing_fee'     => $processing ?: null,
            'platform_shipping_rebate' => $platformShippingRebate ?: null,
            'settlement_amount'        => $settlement,
            'fee_currency'             => 'IDR',
            'is_settled'               => true,
        ];

        $result['fee_lines'] = $this->feeLines($result, [
            'seller_voucher'           => 'promotional_charges',
            'commission_fee'           => 'commission',
            'service_fee'              => 'service_fee',
            'transaction_fee'          => 'payment_fee',
            'order_processing_fee'     => 'order_fulfillment_fee',
            'platform_shipping_rebate' => 'shipping_fee_voucher',
            'settlement_amount'        => 'statement_total',
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

    private function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '', (string) $value);
    }
}
