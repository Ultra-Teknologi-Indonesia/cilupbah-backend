<?php

namespace Modules\Channel\Support;

class TikTokImageUrl
{
    protected const CDN_PREFIX = 'https://p16-oec-ttp.tiktokcdn-us.com/';

    public static function ensureFetchable(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (str_starts_with($url, 'tos-')) {
            return self::CDN_PREFIX . ltrim($url, '/');
        }

        return null;
    }
}
