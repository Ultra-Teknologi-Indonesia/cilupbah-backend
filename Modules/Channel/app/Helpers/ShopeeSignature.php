<?php

namespace Modules\Channel\Helpers;

/**
 * Shopee Open Platform v2 signature helpers.
 *
 * Berbeda dari TikTok/Lazada:
 * - Kredensial: partner_id + partner_key (bukan app_key/app_secret).
 * - Output HMAC-SHA256 hex lowercase (Lazada uppercase).
 * - Timestamp dalam DETIK (Lazada milidetik).
 */
class ShopeeSignature
{
    /**
     * Signature untuk API publik (auth_partner, token/get, access_token/get).
     * base = partner_id + path + timestamp
     */
    public static function publicSign(int|string $partnerId, string $path, int $timestamp, string $partnerKey): string
    {
        $base = $partnerId . $path . $timestamp;

        return hash_hmac('sha256', $base, $partnerKey);
    }

    /**
     * Signature untuk API level-shop (dipakai fase sinkronisasi).
     * base = partner_id + path + timestamp + access_token + shop_id
     */
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

    /**
     * Signature untuk validasi Push (webhook) Shopee.
     * base = push_url + "|" + raw_body, dikirim Shopee di header Authorization.
     */
    public static function pushSign(string $pushUrl, string $rawBody, string $partnerKey): string
    {
        $base = $pushUrl . '|' . $rawBody;

        return hash_hmac('sha256', $base, $partnerKey);
    }
}
