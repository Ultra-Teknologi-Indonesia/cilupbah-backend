<?php

namespace Modules\Channel\Services;

use Modules\Channel\Models\ChannelShop;

class OrderSyncStatusService
{
    public function derive(ChannelShop $shop): array
    {
        if (! $shop->order_sync_enabled) {
            return ['status' => ChannelShop::ORDER_SYNC_INACTIVE, 'note' => 'Sinkron pesanan dinonaktifkan'];
        }

        if ($shop->disconnected_at !== null) {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => 'Toko terputus dari marketplace'];
        }

        if ($shop->integration_status === 'error') {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => $shop->last_error ?: 'Integrasi bermasalah'];
        }

        if ($this->needsReauth($shop)) {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => 'Perlu otorisasi ulang'];
        }

        if ($this->hasUnresolvedPullError($shop)) {
            return ['status' => ChannelShop::ORDER_SYNC_PROBLEM, 'note' => $shop->last_order_error ?: 'Sinkron pesanan gagal'];
        }

        if ($shop->last_order_synced_at === null) {
            return ['status' => ChannelShop::ORDER_SYNC_PENDING, 'note' => 'Menunggu sinkronisasi pertama'];
        }

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

        return $shop->last_order_synced_at === null
            || $shop->last_order_error_at->greaterThan($shop->last_order_synced_at);
    }
}
