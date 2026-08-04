<?php

namespace Modules\Channel\Support;

/**
 * Sumber tunggal teks ramah-pengguna untuk kondisi "perlu otorisasi ulang"
 * dan kegagalan refresh token, plus klasifikasi permanen/sementara.
 *
 * Catatan: Lazada mendapat framing "berkala ~30 hari" karena refresh token-nya
 * terpaku ke otorisasi awal dan TIDAK diperpanjang saat refresh (beda dengan
 * Shopee/TikTok yang rolling). Jadi putusnya Lazada memang rutin, bukan anomali.
 */
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

    /** Teks pendek untuk badge/note di kartu toko. */
    public static function note(?string $code): string
    {
        return 'Koneksi ' . self::channelName($code) . ' kedaluwarsa — hubungkan ulang';
    }

    /** Toast saat tombol Refresh gagal. Bedakan permanen (re-auth) vs sementara. */
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

    /** Pesan saat toko tidak punya refresh token sama sekali (harus re-auth). */
    public static function missingRefreshToken(?string $code): string
    {
        return 'Koneksi ' . self::channelName($code) . ' belum lengkap. '
            . 'Silakan hubungkan ulang toko ini.';
    }

    /**
     * Apakah pesan error mentah dari marketplace menandakan kegagalan PERMANEN
     * (token kedaluwarsa/dicabut → butuh re-auth) alih-alih gangguan sementara.
     */
    public static function isPermanentFailure(string $rawMessage): bool
    {
        $message = strtolower($rawMessage);

        // Sinyal gangguan sementara menang lebih dulu — jangan tandai butuh re-auth.
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
