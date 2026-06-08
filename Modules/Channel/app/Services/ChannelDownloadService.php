<?php

namespace Modules\Channel\Services;

/**
 * Generalisasi proses download (pull) produk dari marketplace per shop,
 * agar tidak terikat ke TikTok saja. Resolusi puller berdasarkan kode channel.
 */
class ChannelDownloadService
{
    /**
     * Tarik produk dari satu toko pada channel tertentu. Mengembalikan jumlah produk.
     */
    public function download(string $channel, string $shopId): int
    {
        return ($this->pullerFor($channel))($shopId);
    }

    /**
     * Tarik produk dari banyak toko sekaligus. Mengembalikan ringkasan per toko.
     *
     * @param  array<int, string>  $shopIds
     * @return array<int, array<string, mixed>>
     */
    public function downloadBulk(string $channel, array $shopIds): array
    {
        $puller = $this->pullerFor($channel);
        $results = [];

        foreach ($shopIds as $shopId) {
            try {
                $count = $puller($shopId);
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
