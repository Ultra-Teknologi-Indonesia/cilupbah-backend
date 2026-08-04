<?php

namespace Modules\Channel\Services;

use Modules\Channel\Models\ChannelShop;

/**
 * SSOT penurunan status "Download Pesanan" per toko.
 *
 * Status pesanan sengaja DIPISAH dari integration_status (kesehatan token/koneksi),
 * tetapi ikut memperhitungkannya: koneksi yang putus otomatis berarti pesanan
 * tidak bisa masuk. Dipakai oleh ChannelShopResource (tampilan) dan
 * EvaluateOrderSyncHealth (scheduler + notifikasi) agar tidak ada drift.
 */
class OrderSyncStatusService
{
    /**
     * @return array{status: string, note: string|null}
     */
    public function derive(ChannelShop $shop): array
    {
        // 1. Sinkron pesanan dimatikan manual → jangan dialarm.
        if (! $shop->order_sync_enabled) {
            return ['status' => ChannelShop::ORDER_SYNC_INACTIVE, 'note' => 'Sinkron pesanan dinonaktifkan'];
        }

        // 2. Tidak bisa sinkron sama sekali (koneksi/token) → Bermasalah.
        if ($shop->disconnected_at !== null) {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => 'Toko terputus dari marketplace'];
        }

        if ($shop->integration_status === 'error') {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => $shop->last_error ?: 'Integrasi bermasalah'];
        }

        if ($this->needsReauth($shop)) {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => 'Perlu otorisasi ulang'];
        }

        // 3. Kegagalan tarik pesanan yang belum tertimpa keberhasilan → Bermasalah.
        if ($this->hasUnresolvedPullError($shop)) {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => $shop->last_order_error ?: 'Sinkron pesanan gagal'];
        }

        // 4. Belum pernah ada konfirmasi pesanan/heartbeat sukses → Tertunda.
        if ($shop->last_order_synced_at === null) {
            return ['status' => ChannelShop::ORDER_SYNC_PENDING, 'note' => 'Menunggu sinkronisasi pertama'];
        }

        // 5. Sehat.
        return ['status' => ChannelShop::ORDER_SYNC_NORMAL, 'note' => null];
    }

    private function needsReauth(ChannelShop $shop): bool
    {
        if (empty($shop->access_token)) {
            return true;
        }

        return $shop->refresh_token_expires_at !== null && $shop->refresh_token_expires_at->isPast();
    }

    private function hasUnresolvedPullError(ChannelShop $shop): bool
    {
        if ($shop->last_order_error_at === null) {
            return false;
        }

        // Error dianggap sembuh bila ada keberhasilan setelahnya.
        return $shop->last_order_synced_at === null
            || $shop->last_order_error_at->greaterThan($shop->last_order_synced_at);
    }
}
