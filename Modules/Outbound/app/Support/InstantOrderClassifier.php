<?php

namespace Modules\Outbound\Support;

class InstantOrderClassifier
{
    public const REGEX = 'instant|instan|same[- ]?day|grab|gojek|gosend|lalamove|paxel same';

    public static function isInstant(?string $shippingProvider, ?string $shippingType = null): bool
    {
        foreach ([$shippingProvider, $shippingType] as $val) {
            if ($val && preg_match('/' . self::REGEX . '/i', $val)) {
                return true;
            }
        }
        return false;
    }
}
