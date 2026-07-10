<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnAppeal;

class SalesReturnDetailSyncService
{

    public const FINAL_DECISIONS = [
        SalesReturn::MP_DECISION_REFUNDED,
        SalesReturn::MP_DECISION_CLOSED,
        SalesReturn::MP_DECISION_NOT_RETURN,
    ];

    public function syncOne(SalesReturn $return): bool
    {
        if ($return->source !== SalesReturn::SOURCE_MARKETPLACE) {
            return false;
        }

        [$prefixChannel, $rawReturnId] = $this->splitChannelReturnId($return->channel_return_id);
        $channel = (string) ($return->order->source ?? $prefixChannel ?? '');
        $shopId = (string) ($return->channel_shop_id ?? '');
        $channelOrderNo = (string) ($return->order->channel_order_no ?? '');

        $update = ['detail_synced_at' => now()];

        if ($channel === '' || $shopId === '') {
            $return->update($update);

            return false;
        }

        $detail = $this->fetchDetail($channel, $shopId, $rawReturnId, $channelOrderNo);

        if ($detail['channel_status'] !== null) {
            $decision = SalesReturn::normalizeMarketplaceDecision($channel, $detail['channel_status']);

            if ($decision !== $return->marketplace_decision) {
                $update['marketplace_decision'] = $decision;
                $update['marketplace_decision_at'] = now();
            }

            $update['marketplace_raw_status'] = $detail['channel_status'];
        }

        if ($detail['reason_code'] !== null) {
            $update['channel_reason_code'] = $detail['reason_code'];
        }
        if ($detail['reason_text'] !== null) {
            $update['channel_reason_text'] = $detail['reason_text'];
        }
        if ($detail['refund_amount'] !== null) {
            $update['refund_amount'] = $detail['refund_amount'];
        }
        if ($detail['refund_currency'] !== null) {
            $update['refund_currency'] = $detail['refund_currency'];
        }
        if ($detail['shipping_fee_original'] !== null) {
            $update['shipping_fee_original'] = $detail['shipping_fee_original'];
        }
        if ($detail['shipping_fee_return'] !== null) {
            $update['shipping_fee_return'] = $detail['shipping_fee_return'];
        }

        if ($detail['tracking_number'] !== null && $detail['tracking_number'] !== $return->return_tracking_number) {
            $update['return_tracking_number'] = $detail['tracking_number'];
            $update['return_carrier'] = $detail['carrier'] ?? $return->return_carrier;
            $update['return_shipped_at'] = $detail['shipped_at'] ?? $return->return_shipped_at;
        }

        $return->update($update);

        if ($rawReturnId) {
            $this->syncHistory($return, $channel, $shopId, $rawReturnId);
        }

        return count($update) > 1;
    }

    protected function fetchDetail(string $channel, string $shopId, ?string $rawReturnId, string $channelOrderNo): array
    {
        $empty = [
            'channel_status' => null, 'reason_code' => null, 'reason_text' => null,
            'refund_amount' => null, 'refund_currency' => null,
            'shipping_fee_original' => null, 'shipping_fee_return' => null,
            'tracking_number' => null, 'carrier' => null, 'shipped_at' => null,
        ];

        try {
            $result = match ($channel) {
                'shopee' => app(ShopeeOrderService::class)
                    ->fetchReturnDetail($shopId, $rawReturnId, $channelOrderNo ?: null),
                'tiktok' => app(TikTokOrderService::class)
                    ->fetchReturnDetail($shopId, $rawReturnId, $channelOrderNo ?: null),
                'lazada' => app(LazadaOrderService::class)
                    ->fetchReturnDetail($shopId, $rawReturnId),
                default => $empty,
            };

            unset($result['raw']);

            return array_merge($empty, $result);
        } catch (\Throwable $e) {
            Log::warning("Sync detail retur gagal ({$channel}): " . $e->getMessage());

            return $empty;
        }
    }

    protected function syncHistory(SalesReturn $return, string $channel, string $shopId, string $rawReturnId): void
    {
        try {
            $result = match ($channel) {
                'shopee' => app(ShopeeOrderService::class)->fetchReturnHistory($shopId, $rawReturnId),
                'tiktok' => app(TikTokOrderService::class)->fetchReturnHistory($shopId, $rawReturnId),
                'lazada' => app(LazadaOrderService::class)->fetchReturnHistory($shopId, $rawReturnId),
                default => ['records' => []],
            };
        } catch (\Throwable $e) {
            Log::warning("Sync riwayat banding retur gagal ({$channel}): " . $e->getMessage());

            return;
        }

        foreach ($result['records'] ?? [] as $record) {
            if (empty($record['timestamp'])) {
                continue;
            }

            SalesReturnAppeal::firstOrCreate([
                'sales_return_id' => $return->id,
                'record_type' => $record['type'] ?? 'UNKNOWN',
                'recorded_at' => $record['timestamp'],
            ], [
                'operator' => $record['operator'] ?? 'PLATFORM',
                'description' => $record['description'] ?? null,
            ]);
        }
    }

    protected function splitChannelReturnId(?string $channelReturnId): array
    {
        if (! $channelReturnId) {
            return [null, null];
        }

        if (str_contains($channelReturnId, ':')) {
            [$prefix, $raw] = explode(':', $channelReturnId, 2);

            return [$prefix ?: null, $raw !== '' ? $raw : null];
        }

        return [null, $channelReturnId];
    }
}
