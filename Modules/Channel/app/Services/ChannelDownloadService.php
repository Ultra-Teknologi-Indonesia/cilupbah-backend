<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\DownloadProductsJob;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Product\Models\ProductSyncLog;

/**
 * Generalisasi proses download (pull) produk dari marketplace per shop.
 * Download bersifat asinkron: membuat DownloadTransaction lalu mengantre job.
 */
class ChannelDownloadService
{
    public function __construct(
        protected ChannelShopRepository $channelShopRepository,
    ) {}

    /**
     * Mulai download satu toko: buat transaksi (queued) + antre job. Asinkron.
     */
    public function download(string $channel, string $shopId, ?string $executedBy = null): DownloadTransaction
    {
        $this->assertSupported($channel);
        $channelShopId = $this->requireChannelShopId($shopId);

        $transaction = DownloadTransaction::create([
            'channel_shop_id' => $channelShopId,
            'executed_by' => $executedBy,
            'state' => DownloadTransaction::STATE_QUEUED,
        ]);

        DownloadProductsJob::dispatch($transaction->id, $channel, $shopId)->afterCommit();

        return $transaction;
    }

    /**
     * Mulai download banyak toko. Mengembalikan daftar transaksi yang dibuat.
     *
     * @param  array<int, string>  $shopIds
     * @return array<int, DownloadTransaction>
     */
    public function downloadBulk(string $channel, array $shopIds, ?string $executedBy = null): array
    {
        $this->assertSupported($channel);

        $transactions = [];
        foreach ($shopIds as $shopId) {
            try {
                $transactions[] = $this->download($channel, $shopId, $executedBy);
            } catch (\Throwable $e) {
                Log::warning("Download massal: lewati toko {$shopId} — {$e->getMessage()}");
            }
        }

        return $transactions;
    }

    /**
     * Eksekusi pull aktual (dipanggil oleh job). Mengembalikan jumlah produk +
     * mencatat ke product_sync_logs (action=download).
     */
    public function pull(string $channel, string $shopId): int
    {
        $channelShopId = $this->channelShopRepository->getIdByShopId($shopId);

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

    protected function assertSupported(string $channel): void
    {
        if (! in_array(strtolower($channel), ['tiktok', 'lazada'], true)) {
            throw new \RuntimeException("Channel '{$channel}' belum didukung untuk download", 422);
        }
    }

    protected function requireChannelShopId(string $shopId): string
    {
        $channelShopId = $this->channelShopRepository->getIdByShopId($shopId);

        if (! $channelShopId) {
            throw new \RuntimeException('Toko tidak ditemukan', 422);
        }

        return $channelShopId;
    }

    /**
     * @return callable(string):int
     */
    protected function pullerFor(string $channel): callable
    {
        return match (strtolower($channel)) {
            'tiktok' => fn (string $shopId) => app(TikTokProductService::class)->pullProducts($shopId),
            'lazada' => fn (string $shopId) => app(LazadaProductService::class)->pullProducts($shopId),
            default => throw new \RuntimeException("Channel '{$channel}' belum didukung untuk download", 422),
        };
    }
}
