<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OAuthFlow
{
    private const STATE_TTL_MINUTES = 10;
    private const STATE_PREFIX = 'oauth:state:';

    public static function issueState(string $channel): string
    {
        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX . $state, $channel, now()->addMinutes(self::STATE_TTL_MINUTES));

        return $state;
    }

    public static function consumeState(?string $state, string $channel): bool
    {
        if (! $state) {
            return false;
        }

        return Cache::pull(self::STATE_PREFIX . $state) === $channel;
    }

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
