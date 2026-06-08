<?php

namespace Modules\Channel\Services;

use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductSyncLog;

/**
 * Generalisasi proses download (pull) produk dari marketplace per shop,
 * agar tidak terikat ke TikTok saja. Resolusi puller berdasarkan kode channel.
 */
class ChannelDownloadService
{
    /**
     * Tarik produk dari satu toko pada channel tertentu. Mengembalikan jumlah produk.
     * Mencatat hasilnya ke product_sync_logs (action=download).
     */
    public function download(string $channel, string $shopId): int
    {
        $channelShopId = ChannelShop::where('shop_id', $shopId)->value('id');

        try {
            $count = ($this->pullerFor($channel))($shopId);
        } catch (\Throwable $e) {
            ProductSyncLog::record([
                'channel_shop_id' => $channelShopId,
                'action' => ProductSyncLog::ACTION_DOWNLOAD,
                'status' => ProductSyncLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        ProductSyncLog::record([
            'channel_shop_id' => $channelShopId,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
            'response' => ['pulled_count' => $count],
        ]);

        return $count;
    }

    /**
     * Tarik produk dari banyak toko sekaligus. Mengembalikan ringkasan per toko.
     *
     * @param  array<int, string>  $shopIds
     * @return array<int, array<string, mixed>>
     */
    public function downloadBulk(string $channel, array $shopIds): array
    {
        $results = [];

        foreach ($shopIds as $shopId) {
            try {
                $count = $this->download($channel, $shopId);
                $results[] = [
                    'shop_id' => $shopId,
                    'status' => 'success',
                    'pulled_count' => $count,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'shop_id' => $shopId,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Pemetaan kode channel → fungsi pull. Tambahkan channel lain di sini.
     *
     * @return callable(string):int
     */
    protected function pullerFor(string $channel): callable
    {
        return match (strtolower($channel)) {
            'tiktok' => fn (string $shopId) => app(TikTokProductService::class)->pullProducts($shopId),
            default => throw new \RuntimeException("Channel '{$channel}' belum didukung untuk download", 422),
        };
    }
}
