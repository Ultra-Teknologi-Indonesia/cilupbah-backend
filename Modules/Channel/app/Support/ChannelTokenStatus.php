<?php

namespace Modules\Channel\Support;

use Modules\Channel\Models\ChannelShop;

/**
 * Sumber tunggal status koneksi channel (token_status + integration).
 *
 * Prinsip: access token berumur pendek dan di-refresh otomatis, jadi BUKAN penanda
 * kesehatan koneksi. Deadline yang bermakna adalah refresh_token_expires_at — kapan
 * otorisasi habis dan toko wajib dihubungkan ulang. WooCommerce memakai consumer key
 * (Basic Auth, tanpa token) sehingga tak pernah "kedaluwarsa".
 */
class ChannelTokenStatus
{
    public const REAUTH_WARNING_DAYS = 3;

    public static function hasCredentials(ChannelShop $shop): bool
    {
        return ! empty($shop->access_token) || ! empty($shop->consumer_key);
    }

    public static function status(ChannelShop $shop): string
    {
        if (! $shop->is_active || ! self::hasCredentials($shop)) {
            return 'disconnected';
        }

        $reauthAt = $shop->refresh_token_expires_at;
        if (! $reauthAt) {
            return 'active';
        }

        if ($reauthAt->isPast()) {
            return 'expired';
        }

        if ($reauthAt->lt(now()->addDays(self::REAUTH_WARNING_DAYS))) {
            return 'expiring_soon';
        }

        return 'active';
    }

    public static function integration(ChannelShop $shop): array
    {
        $reauthAt = $shop->refresh_token_expires_at;

        if (! self::hasCredentials($shop) || ($reauthAt && $reauthAt->isPast())) {
            return ['status' => 'error', 'note' => ChannelReauthCopy::note($shop->channel?->code), 'action' => 'reauth'];
        }

        if ($shop->integration_status === 'error') {
            return ['status' => 'error', 'note' => $shop->last_error ?: 'Integrasi bermasalah', 'action' => null];
        }

        if ($reauthAt && $reauthAt->isFuture() && $reauthAt->lt(now()->addDays(self::REAUTH_WARNING_DAYS))) {
            $hours = now()->diffInHours($reauthAt);
            $note = $hours < 24
                ? 'Otorisasi berakhir < 24 jam — hubungkan ulang'
                : 'Otorisasi berakhir dalam ' . max(1, (int) round($hours / 24)) . ' hari — hubungkan ulang';

            return ['status' => 'warning', 'note' => $note, 'action' => 'reauth'];
        }

        if ($shop->integration_status === 'warning') {
            return ['status' => 'warning', 'note' => $shop->last_error ?: 'Perlu perhatian', 'action' => null];
        }

        return ['status' => 'normal', 'action' => null];
    }
}
