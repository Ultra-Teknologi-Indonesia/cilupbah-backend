<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
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

        if ($detail['channel_status'] === null && $rawReturnId && $channelOrderNo !== '') {
            $orderDetail = $this->fetchDetail($channel, $shopId, null, $channelOrderNo);
            $detail = $this->preferPopulatedDetail($detail, $orderDetail);
        }

        if ($detail['channel_status'] === null && $rawReturnId) {
            $webhookDetail = $this->findLatestWebhookDetail($channel, $shopId, $rawReturnId);
            $detail = $this->preferPopulatedDetail($detail, $webhookDetail);
        }

        if ($detail['channel_status'] !== null) {
            $decision = SalesReturn::normalizeMarketplaceDecision($channel, $detail['channel_status']);

            if ($return->reason_category === SalesReturn::REASON_CATEGORY_FAILED_DELIVERY) {
                $decision = SalesReturn::MP_DECISION_NOT_RETURN;
            }

            if (
                $channel === 'shopee'
                && $decision === SalesReturn::MP_DECISION_CLOSED
                && $detail['refund_amount'] !== null
                && (float) $detail['refund_amount'] === 0.0
            ) {
                $decision = SalesReturn::MP_DECISION_NOT_RETURN;
            }

            if (SalesReturn::shouldApplyMarketplaceDecision($return->marketplace_decision, $decision)) {
                $update['marketplace_raw_status'] = $detail['channel_status'];

                if ($decision !== $return->marketplace_decision || $return->marketplace_decision_at === null) {
                    $update['marketplace_decision'] = $decision;
                    $update['marketplace_decision_at'] = now();
                }
            } elseif ($decision === $return->marketplace_decision) {
                $update['marketplace_raw_status'] = $detail['channel_status'];
            }
        }

        if ($detail['channel_status'] !== null && ! isset($update['marketplace_raw_status'])) {
            Log::info('Status retur marketplace diabaikan karena lebih lama dari keputusan tersimpan.', [
                'sales_return_id' => $return->id,
                'current_decision' => $return->marketplace_decision,
                'incoming_status' => $detail['channel_status'],
            ]);
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

    private function preferPopulatedDetail(array $current, array $candidate): array
    {
        if (($current['channel_status'] ?? null) !== null) {
            return $current;
        }

        return array_merge($current, array_filter(
            $candidate,
            static fn ($value) => $value !== null && $value !== '',
        ));
    }

    private function findLatestWebhookDetail(string $channel, string $shopId, string $rawReturnId): array
    {
        $empty = [
            'channel_status' => null,
            'reason_code' => null,
            'reason_text' => null,
            'refund_amount' => null,
            'refund_currency' => null,
            'shipping_fee_original' => null,
            'shipping_fee_return' => null,
            'tracking_number' => null,
            'carrier' => null,
            'shipped_at' => null,
            'raw' => [],
        ];

        try {
            $escapedId = addcslashes($rawReturnId, '%_\\');
            $inboxes = DB::table('channel_webhook_inbox')
                ->where('channel', $channel)
                ->where('shop_id', $shopId)
                ->whereRaw('payload::text ILIKE ?', ["%{$escapedId}%"])
                ->orderByDesc('received_at')
                ->limit(25)
                ->get(['payload', 'received_at']);

            foreach ($inboxes as $inbox) {
                $payload = is_array($inbox->payload)
                    ? $inbox->payload
                    : json_decode((string) $inbox->payload, true);
                if (! is_array($payload)) {
                    continue;
                }

                $status = $this->findWebhookStatus($payload);
                if ($status === null) {
                    continue;
                }

                return array_merge($empty, [
                    'channel_status' => $status,
                    'raw' => [
                        'source' => 'channel_webhook_inbox',
                        'received_at' => $inbox->received_at,
                        'payload' => $payload,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('Fallback status retur dari webhook gagal.', [
                'channel' => $channel,
                'return_id' => $rawReturnId,
                'error' => $e->getMessage(),
            ]);
        }

        return $empty;
    }

    private function findWebhookStatus(array $payload): ?string
    {
        $preferredKeys = [
            'return_status',
            'reverse_status',
            'return_order_status',
            'refund_status',
            'aftersales_request_status',
        ];

        $nodes = [$payload];
        while ($nodes !== []) {
            $node = array_pop($nodes);
            if (! is_array($node)) {
                continue;
            }

            foreach ($preferredKeys as $key) {
                $value = $node[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            foreach ($node as $key => $value) {
                if ($key === 'status' && is_string($value) && $this->isKnownChannelStatus($value)) {
                    return trim($value);
                }
                if (is_array($value)) {
                    $nodes[] = $value;
                }
            }
        }

        return null;
    }

    private function isKnownChannelStatus(string $status): bool
    {
        return in_array(strtoupper(trim($status)), [
            'REQUESTED', 'ACCEPTED', 'PROCESSING', 'SELLER_DISPUTE', 'JUDGING',
            'CANCELLED', 'CLOSED', 'EXPIRED', 'REFUNDED', 'REJECTED',
            'RETURN_OR_REFUND_REQUEST_PENDING', 'AWAITING_BUYER_SHIP',
            'BUYER_SHIPPED_ITEM', 'REQUEST_SUCCESS', 'REQUEST_REJECTED',
            'RETURN_OR_REFUND_REQUEST_REJECT', 'REFUND_OR_RETURN_REQUEST_REJECT',
            'RECEIVE_REJECTED', 'REJECT_RECEIVE_PACKAGE',
            'RETURN_OR_REFUND_REQUEST_COMPLETE', 'RETURN_OR_REFUND_CANCEL',
            'RETURN_OR_REFUND_REQUEST_CANCEL', 'REPLACEMENT_REQUEST_CANCEL',
            'REPLACEMENT_REQUEST_REJECT', 'REFUND_SUCCESS',
        ], true);
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
