<?php

namespace Modules\Channel\Helpers;

class WooCommerceSignature
{
    public static function sign(string $rawBody, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    }

    public static function verify(string $rawBody, string $secret, string $provided): bool
    {
        if ($secret === '' || $provided === '') {
            return false;
        }

        return hash_equals(self::sign($rawBody, $secret), $provided);
    }
}
