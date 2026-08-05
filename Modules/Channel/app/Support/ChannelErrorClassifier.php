<?php

namespace Modules\Channel\Support;

use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Exceptions\TikTokApiException;
use Modules\Channel\Exceptions\TokenExpiredException;

class ChannelErrorClassifier
{
    protected const RETRYABLE_MARKERS = [
        'frequency',
        'call limit',
        'rate limit',
        'too many request',
        'too many requests',
        'system busy',
        'system_busy',
        'system error',
        'try again',
        'timeout',
        'timed out',
        'temporarily',
        'service unavailable',
        'gateway',
        'http error [5',
        'http error [429',
        'dependency service',
    ];

    public static function isRetryable(string $channelCode, \Throwable $e): bool
    {
        if ($e instanceof TokenExpiredException) {
            return false;
        }

        if ($e instanceof ShopeeApiException || $e instanceof TikTokApiException) {
            return $e->isRetryable();
        }

        $message = mb_strtolower($e->getMessage());

        if ($channelCode === 'lazada' && str_contains($message, 'lazada api error')) {
            return LazadaErrorCatalog::resolve($e->getMessage())['category'] === LazadaErrorCatalog::RETRYABLE;
        }

        foreach (self::RETRYABLE_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function retryAfterSeconds(\Throwable $e): ?int
    {
        if (preg_match('/(?:last|in|after|retry after)\s+(\d+)\s*second/i', $e->getMessage(), $m)) {
            return max(1, min(30, (int) $m[1]));
        }

        return null;
    }
}
