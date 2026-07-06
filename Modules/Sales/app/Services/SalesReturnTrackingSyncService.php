<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Models\SalesReturn;

/**
 * Menarik nomor resi ekspedisi retur (paket yang dikirim balik buyer) dari marketplace
 * dan menyimpannya ke record SalesReturn. Idempoten & fail-soft.
 */
class SalesReturnTrackingSyncService
{
    /**
     * Sinkronkan resi untuk satu retur. Return true bila resi berhasil terisi/diperbarui.
     */
    public function syncOne(SalesReturn $return): bool
    {
        if ($return->source !== SalesReturn::SOURCE_MARKETPLACE) {
            return false;
        }

        // Channel sebenarnya (shopee/tiktok/lazada) ada di order->source & prefix channel_return_id,
        // BUKAN di kolom source retur (yang selalu "marketplace").
        [$prefixChannel, $rawReturnId] = $this->splitChannelReturnId($return->channel_return_id);
        $channel = (string) ($return->order->source ?? $prefixChannel ?? '');
        $shopId = (string) ($return->channel_shop_id ?? '');
        $channelOrderNo = (string) ($return->order->channel_order_no ?? '');

        if ($channel === '' || $shopId === '') {
            return false;
        }

        $result = $this->fetch($channel, $shopId, $rawReturnId, $channelOrderNo);

        // Selalu tandai kapan terakhir dicoba agar penjadwalan tidak mengulang terus-menerus.
        $update = ['tracking_synced_at' => now()];

        $tracking = $result['tracking_number'] ?? null;
        if ($tracking && $tracking !== $return->return_tracking_number) {
            $update['return_tracking_number'] = $tracking;
            $update['return_carrier'] = $result['carrier'] ?? $return->return_carrier;
            $update['return_shipped_at'] = $result['shipped_at'] ?? $return->return_shipped_at;
        }

        $return->update($update);

        return isset($update['return_tracking_number']);
    }

    /**
     * @return array{tracking_number: ?string, carrier: ?string, shipped_at: ?string}
     */
    protected function fetch(string $source, string $shopId, ?string $rawReturnId, string $channelOrderNo): array
    {
        $empty = ['tracking_number' => null, 'carrier' => null, 'shipped_at' => null];

        try {
            return match ($source) {
                'shopee' => app(ShopeeOrderService::class)
                    ->fetchReturnTracking($shopId, $rawReturnId, $channelOrderNo ?: null),
                'tiktok' => app(TikTokOrderService::class)
                    ->fetchReturnTracking($shopId, $rawReturnId, $channelOrderNo ?: null),
                'lazada' => app(LazadaOrderService::class)
                    ->fetchReturnTracking($shopId, $rawReturnId),
                default  => $empty,
            };
        } catch (\Throwable $e) {
            Log::warning("Sync resi retur gagal ({$source}): " . $e->getMessage());

            return $empty;
        }
    }

    /**
     * channel_return_id disimpan sebagai "source:rawId" (lihat SalesReturnService::createFromChannel).
     * Kembalikan [prefixChannel|null, rawId|null].
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
