<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Jobs\ManualStockResyncAllJob;
use Modules\Channel\Models\ChannelSyncSetting;

/**
 * Master switch global untuk SELURUH sinkronisasi channel (semua toko, semua channel).
 *
 * Saat OFF, semua jalur sync menjadi jeda non-destruktif:
 * - Outbound (stok/harga/produk) di-gate di SyncProductToChannelJob::handle().
 * - Inbound bulk (pull pesanan manual + heartbeat) di-gate di OrderService::pullOrders().
 * - Inbound webhook per-order di-gate di Process*Webhook::handle() (baris inbox tetap
 *   RECEIVED, di-drain otomatis oleh channel:webhooks-replay saat dinyalakan lagi).
 * Pengaturan sync per-listing (variant sync_enabled) TIDAK diubah — nyala lagi = kembali normal.
 */
class ChannelSyncSettingService
{
    public const CACHE_KEY = 'channel_sync_enabled';

    public function current(): ChannelSyncSetting
    {
        return ChannelSyncSetting::query()->firstOrCreate([], ['sync_enabled' => true]);
    }

    public function isEnabled(): bool
    {
        return (bool) Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            fn () => $this->current()->sync_enabled,
        );
    }

    public function isPaused(): bool
    {
        return ! $this->isEnabled();
    }

    public function setEnabled(bool $enabled): ChannelSyncSetting
    {
        $setting = $this->current();
        $wasEnabled = (bool) $setting->sync_enabled;

        $setting->update(['sync_enabled' => $enabled]);
        Cache::forget(self::CACHE_KEY);

        // Saat dinyalakan kembali dari kondisi jeda, dorong resync stok massal agar
        // channel menyusul perubahan stok/harga lokal yang terjadi selama jeda.
        if ($enabled && ! $wasEnabled) {
            ManualStockResyncAllJob::dispatch([]);
        }

        return $setting->refresh();
    }
}
