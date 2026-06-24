<?php

namespace Modules\Channel\Support;

/**
 * Normalises TikTok product image references into absolute URLs.
 *
 * TikTok returns SKU images either as a fully-formed, servable URL
 * (`url_list` / `urls`) or as a bare object-storage key
 * (`uri = tos-alisg-i-<token>-sg/<hash>`). Only the full URLs are reachable
 * server-side, so callers must prefer those; the bare key is expanded to an
 * absolute URL here purely as a best-effort last resort.
 */
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
