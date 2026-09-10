<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Exceptions\SalesReturnTrackingSyncException;
use Modules\Sales\Models\SalesReturn;

class SalesReturnTrackingSyncService
{

    public function syncOne(SalesReturn $return): bool
    {
        if ($return->source !== SalesReturn::SOURCE_MARKETPLACE) {
            return false;
        }

        $return->loadMissing('order:id,source,channel_order_no');
        $return->forceFill([
            'tracking_sync_status' => SalesReturn::TRACKING_SYNC_IN_PROGRESS,
            'tracking_sync_attempted_at' => now(),
            'tracking_sync_last_error' => null,
        ])->saveQuietly();

        try {
            [$prefixChannel, $rawReturnId] = $this->splitChannelReturnId($return->channel_return_id);
            $channel = (string) ($return->order->source ?? $prefixChannel ?? '');
            $shopId = (string) ($return->channel_shop_id ?? '');
            $channelOrderNo = (string) ($return->order->channel_order_no ?? '');

            if ($channel === '' || $shopId === '') {
                $this->markFailed($return, 'channel atau channel shop tidak tersedia', false);

                return false;
            }

            $result = $this->fetch($channel, $shopId, $rawReturnId, $channelOrderNo);

            if (! ($result['_request_succeeded'] ?? false)) {
                throw new SalesReturnTrackingSyncException(
                    (string) ($result['_failure_reason'] ?? 'request tracking marketplace gagal'),
                    (string) $return->id,
                );
            }

            $tracking = $result['tracking_number'] ?? null;
            $update = [
                'tracking_synced_at' => now(),
                'tracking_sync_status' => $tracking
                    ? SalesReturn::TRACKING_SYNC_SUCCEEDED
                    : SalesReturn::TRACKING_SYNC_NO_TRACKING,
                'tracking_sync_last_error' => null,
            ];

            if ($tracking && $tracking !== $return->return_tracking_number) {
                $update['return_tracking_number'] = $tracking;
                $update['return_carrier'] = $result['carrier'] ?? $return->return_carrier;
                $update['return_shipped_at'] = $result['shipped_at'] ?? $return->return_shipped_at;
            }

            $return->forceFill($update)->saveQuietly();

            return $tracking !== null && $tracking !== '';
        } catch (\Throwable $e) {
            $this->markFailed($return, $e->getMessage(), $e instanceof SalesReturnTrackingSyncException
                ? $e->retryable
                : true);

            throw $e;
        }
    }

    protected function fetch(string $source, string $shopId, ?string $rawReturnId, string $channelOrderNo): array
    {
        $empty = [
            'tracking_number' => null,
            'carrier' => null,
            'shipped_at' => null,
            '_request_succeeded' => false,
        ];

        try {
            $result = match ($source) {
                'shopee' => app(ShopeeOrderService::class)
                    ->fetchReturnTracking($shopId, $rawReturnId, $channelOrderNo ?: null),
                'tiktok' => app(TikTokOrderService::class)
                    ->fetchReturnTracking($shopId, $rawReturnId, $channelOrderNo ?: null),
                'lazada' => app(LazadaOrderService::class)
                    ->fetchReturnTracking($shopId, $rawReturnId),
                default  => $empty,
            };

            return array_merge($result, [
                '_request_succeeded' => (bool) ($result['_request_succeeded'] ?? false),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Sync resi retur gagal ({$source}): " . $e->getMessage());

            return $empty + ['_failure_reason' => $e->getMessage()];
        }
    }

    private function markFailed(SalesReturn $return, string $message, bool $retryable): void
    {
        $return->forceFill([
            'tracking_sync_status' => $retryable
                ? SalesReturn::TRACKING_SYNC_FAILED
                : SalesReturn::TRACKING_SYNC_BLOCKED,
            'tracking_sync_last_error' => Str::limit($message, 2000),
        ])->saveQuietly();

        Log::warning('Sinkronisasi tracking retur gagal.', [
            'sales_return_id' => $return->id,
            'retryable' => $retryable,
            'error' => $message,
        ]);
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
