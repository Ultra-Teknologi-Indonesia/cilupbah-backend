<?php

namespace Modules\Outbound\Support;

class InstantOrderClassifier
{
    public const REGEX = 'instant|instan|same[- ]?day|grab|gojek|gosend|lalamove|borzo|bluebird|rara|paxel same';

    public static function isInstant(?string $shippingProvider, ?string $shippingType = null): bool
    {
        foreach ([$shippingProvider, $shippingType] as $val) {
            if ($val && preg_match('/' . self::REGEX . '/i', $val)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deteksi tier prioritas (mis. "Instant Prioritas", "SPX Instant Prioritas",
     * "Priority") dari nama layanan/kurir. Cocokkan "priorit" (prioritas/priority).
     */
    public static function isPriority(?string $shippingProvider, ?string $shippingType = null): bool
    {
        foreach ([$shippingProvider, $shippingType] as $val) {
            if ($val && preg_match('/priorit/i', $val)) {
                return true;
            }
        }
        return false;
    }
}
