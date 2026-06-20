<?php

namespace Modules\Channel\Helpers;

class ShopeeSignature
{

    public static function publicSign(int|string $partnerId, string $path, int $timestamp, string $partnerKey): string
    {
        $base = $partnerId . $path . $timestamp;

        return hash_hmac('sha256', $base, $partnerKey);
    }

    public static function shopSign(
        int|string $partnerId,
        string $path,
        int $timestamp,
        string $accessToken,
        int|string $shopId,
        string $partnerKey
    ): string {
        $base = $partnerId . $path . $timestamp . $accessToken . $shopId;

        return hash_hmac('sha256', $base, $partnerKey);
    }

    public static function pushSign(string $pushUrl, string $rawBody, string $partnerKey): string
    {
        $base = $pushUrl . '|' . $rawBody;

        return hash_hmac('sha256', $base, $partnerKey);
    }
}
