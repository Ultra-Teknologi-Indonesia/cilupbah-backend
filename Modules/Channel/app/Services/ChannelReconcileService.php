<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\ReconcileChannelDataJob;
use Modules\Channel\Models\ChannelShop;

/**
 * Orkestrasi rekonsiliasi data channel (non-destruktif) untuk tombol Refresh
 * Pantauan. Hanya channel yang punya implementasi pull (TikTok, Lazada).
 */
class ChannelReconcileService
{
    protected function reconcilerFor(string $channel): ?callable
    {
        return match (strtolower($channel)) {
            'tiktok' => fn (string $shopId) => app(TikTokProductService::class)->reconcileChannelData($shopId),
            'lazada' => fn (string $shopId) => app(LazadaProductService::class)->reconcileChannelData($shopId),
            default => null,
        };
    }

    public function reconcile(string $channel, string $shopId): int
    {
        $reconciler = $this->reconcilerFor($channel);
        if (! $reconciler) {
            return 0;
        }

        try {
            return $reconciler($shopId);
        } catch (\Throwable $e) {
            Log::error("Reconcile {$channel} shop {$shopId} gagal: " . $e->getMessage());

            return 0;
        }
    }

    /**
     * Antrekan rekonsiliasi semua toko aktif yang channel-nya didukung.
     *
     * @return array{queued:int, skipped_channels:array<int,string>}
     */
    public function reconcileAllActive(?string $userId = null): array
    {
        $shops = ChannelShop::with('channel')
            ->where('is_active', true)
            ->whereNull('disconnected_at')
            ->get();

        $queued = 0;
        $skipped = [];

        foreach ($shops as $shop) {
            $channel = $shop->channel?->code;

            if (! $channel || ! $this->reconcilerFor($channel)) {
                if ($channel) {
                    $skipped[] = $channel;
                }
                continue;
            }

            ReconcileChannelDataJob::dispatch($channel, $shop->shop_id)->afterCommit();
            $queued++;
        }

        return ['queued' => $queued, 'skipped_channels' => array_values(array_unique($skipped))];
    }
}
