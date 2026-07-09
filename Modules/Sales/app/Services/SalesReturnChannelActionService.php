<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Models\SalesReturn;

/**
 * Meneruskan aksi terima/tolak retur channel online (yang diinput admin gudang di
 * FE) ke marketplace terkait, lalu menyinkronkan keputusan terbaru ke SalesReturn.
 * Dispatcher tipis di atas method per-channel di Modules\Channel — mirror pola
 * SalesReturnTrackingSyncService/SalesReturnDetailSyncService.
 */
class SalesReturnChannelActionService
{
    public function accept(SalesReturn $return): bool
    {
        [$channel, $shopId, $rawReturnId] = $this->resolve($return);

        if (! $channel || ! $shopId || ! $rawReturnId) {
            return false;
        }

        try {
            $result = match ($channel) {
                'shopee' => app(ShopeeOrderService::class)->confirmReturn($shopId, $rawReturnId),
                'tiktok' => app(TikTokOrderService::class)->approveReturn($shopId, $rawReturnId),
                'lazada' => app(LazadaOrderService::class)->approveReturn($shopId, $rawReturnId),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning("Terima retur ke channel gagal ({$channel}): " . $e->getMessage());

            return false;
        }

        if ($result) {
            app(SalesReturnDetailSyncService::class)->syncOne($return->fresh());
        }

        return $result;
    }

    public function reject(SalesReturn $return, string $reasonId, ?string $note = null): bool
    {
        [$channel, $shopId, $rawReturnId] = $this->resolve($return);

        if (! $channel || ! $shopId || ! $rawReturnId) {
            return false;
        }

        try {
            $result = match ($channel) {
                'shopee' => app(ShopeeOrderService::class)->disputeReturn($shopId, $rawReturnId, $reasonId, $note),
                'tiktok' => app(TikTokOrderService::class)->rejectReturn($shopId, $rawReturnId, $reasonId, $note),
                'lazada' => app(LazadaOrderService::class)->rejectReturn($shopId, $rawReturnId, $reasonId, $note),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning("Tolak retur ke channel gagal ({$channel}): " . $e->getMessage());

            return false;
        }

        if ($result) {
            app(SalesReturnDetailSyncService::class)->syncOne($return->fresh());
        }

        return $result;
    }

    /**
     * @return array<int, array{id: string, text: string}>
     */
    public function getRejectReasons(SalesReturn $return): array
    {
        [$channel, $shopId, $rawReturnId] = $this->resolve($return);

        if (! $channel || ! $shopId) {
            return [];
        }

        try {
            return match ($channel) {
                'shopee' => app(ShopeeOrderService::class)->getDisputeReasons(),
                'tiktok' => $rawReturnId ? app(TikTokOrderService::class)->getRejectReasons($shopId, $rawReturnId) : [],
                'lazada' => $rawReturnId ? app(LazadaOrderService::class)->getRejectReasons($shopId, $rawReturnId) : [],
                default => [],
            };
        } catch (\Throwable $e) {
            Log::warning("Ambil alasan tolak retur gagal ({$channel}): " . $e->getMessage());

            return [];
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [channel, shopId, rawReturnId]
     */
    protected function resolve(SalesReturn $return): array
    {
        if ($return->source !== SalesReturn::SOURCE_MARKETPLACE) {
            return [null, null, null];
        }

        [$prefixChannel, $rawReturnId] = $this->splitChannelReturnId($return->channel_return_id);
        $channel = (string) ($return->order->source ?? $prefixChannel ?? '');
        $shopId = (string) ($return->channel_shop_id ?? '');

        return [$channel ?: null, $shopId ?: null, $rawReturnId];
    }

    /**
     * channel_return_id disimpan sebagai "source:rawId" (lihat SalesReturnService::createFromChannel).
     *
     * @return array{0: ?string, 1: ?string}
     */
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
