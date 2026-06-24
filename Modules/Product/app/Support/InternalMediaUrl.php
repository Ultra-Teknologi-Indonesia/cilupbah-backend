<?php

namespace Modules\Product\Support;

/**
 * Helpers to tell whether a media URL already lives on our internal CDN
 * (the S3 bucket exposed at assets.ultra-fit.id) versus an external channel
 * CDN that still needs mirroring.
 */
class InternalMediaUrl
{
    public static function host(): ?string
    {
        $base = config('filesystems.disks.s3.url') ?: config('app.url');

        return $base ? parse_url($base, PHP_URL_HOST) : null;
    }

    public static function isInternal(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        $host = self::host();

        return $host !== null && parse_url($url, PHP_URL_HOST) === $host;
    }

    public static function isExternal(?string $url): bool
    {
        return ! empty($url) && ! self::isInternal($url);
    }
}
