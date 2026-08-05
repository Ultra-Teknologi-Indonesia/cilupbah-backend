<?php

namespace Modules\Channel\Support;

class ChannelReauthCopy
{
    public static function channelName(?string $code): string
    {
        return match ($code) {
            'lazada' => 'Lazada',
            'shopee' => 'Shopee',
            'tiktok', 'tokopedia' => 'TikTok Shop',
            'woocommerce' => 'WooCommerce',
            'blibli' => 'Blibli',
            default => 'marketplace',
        };
    }

    public static function note(?string $code): string
    {
        return 'Koneksi ' . self::channelName($code) . ' kedaluwarsa — hubungkan ulang';
    }

    public static function refreshFailure(?string $code, bool $permanent): string
    {
        $name = self::channelName($code);

        if ($permanent) {
            return "Koneksi {$name} sudah kedaluwarsa dan tidak bisa diperbarui otomatis. "
                . 'Silakan hubungkan ulang toko ini.';
        }

        return "Gagal memperbarui koneksi {$name} karena gangguan sementara. "
            . 'Coba lagi beberapa saat.';
    }

    public static function missingRefreshToken(?string $code): string
    {
        return 'Koneksi ' . self::channelName($code) . ' belum lengkap. '
            . 'Silakan hubungkan ulang toko ini.';
    }

    public static function isPermanentFailure(string $rawMessage): bool
    {
        $message = strtolower($rawMessage);

        foreach ([
            'timeout', 'timed out', 'curl', 'could not resolve', 'connection',
            'temporar', 'server error', 'service unavailable', 'unknown error',
            'try again', 'rate limit', 'too many',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return false;
            }
        }

        foreach ([
            'invalid_grant', 'illegalrefreshtoken', 'invalidrefreshtoken',
            'illegalaccesstoken', 'invalidaccesstoken', 'invalid refresh token',
            'invalid access token', 'expired', 'revoke', 'unauthor', 'not authorized',
            'reauth', 're-auth', 'tidak tersedia', 'invalid_token',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
