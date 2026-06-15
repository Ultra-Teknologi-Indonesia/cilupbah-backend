<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Helper flow OAuth channel: state CSRF (nonce sekali pakai di cache) dan
 * pembuatan URL redirect balik ke frontend setelah callback.
 */
class OAuthFlow
{
    private const STATE_TTL_MINUTES = 10;
    private const STATE_PREFIX = 'oauth:state:';

    /** Buat state acak, simpan channel-nya di cache (TTL 10 menit). */
    public static function issueState(string $channel): string
    {
        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX . $state, $channel, now()->addMinutes(self::STATE_TTL_MINUTES));

        return $state;
    }

    /** Verifikasi + konsumsi state (sekali pakai). True bila cocok dengan channel. */
    public static function consumeState(?string $state, string $channel): bool
    {
        if (! $state) {
            return false;
        }

        return Cache::pull(self::STATE_PREFIX . $state) === $channel;
    }

    /**
     * URL halaman integrasi channel di frontend dengan query status.
     * Null bila FRONTEND_URL belum diset (pemanggil fallback ke JSON).
     */
    public static function frontendUrl(string $channel, array $params): ?string
    {
        $base = config('app.frontend_url');

        if (! $base) {
            return null;
        }

        $query = http_build_query(array_merge(['connected' => $channel], $params));

        return rtrim((string) $base, '/') . '/dashboard/integrasi-channel?' . $query;
    }
}
