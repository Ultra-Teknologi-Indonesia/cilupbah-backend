<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class MediaMirrorException extends RuntimeException
{
    public function __construct(
        public readonly string $sourceUrl,
        \Throwable $previous,
    ) {
        parent::__construct(
            'Media mirror gagal: '.self::safeUrl($sourceUrl).' — '.$previous->getMessage(),
            (int) $previous->getCode(),
            $previous,
        );
    }

    private static function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '[invalid-url]';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        return $scheme.$host.$path;
    }
}
