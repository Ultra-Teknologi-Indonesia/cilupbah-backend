<?php

namespace Modules\Outbound\Support;

class InstantOrderClassifier
{
    public const REGEX = 'instant|instan|same[- ]?day|grab|gojek|gosend|lalamove|borzo|bluebird|rara|paxel same';

    public const MANUAL_DRIVER_REGEX = 'gojek|gosend|grab';

    public static function needsManualDriverDispatch(?string ...$values): bool
    {
        foreach ($values as $val) {
            if ($val && preg_match('/'.self::MANUAL_DRIVER_REGEX.'/i', $val)) {
                return true;
            }
        }

        return false;
    }

    public static function isInstant(?string $shippingProvider, ?string $shippingType = null): bool
    {
        foreach ([$shippingProvider, $shippingType] as $val) {
            if ($val && preg_match('/' . self::REGEX . '/i', $val)) {
                return true;
            }
        }
        return false;
    }

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
