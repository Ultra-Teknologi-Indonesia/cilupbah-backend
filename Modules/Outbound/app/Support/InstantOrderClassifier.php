<?php

namespace Modules\Outbound\Support;

class InstantOrderClassifier
{
    public const REGEX = 'instant|instan|same[- ]?day|grab|gojek|gosend|lalamove|borzo|bluebird|rara|paxel same';

    public const MANUAL_DRIVER_REGEX = 'gojek|gosend|grab';

    public const MANUAL_DRIVER_UMBRELLA = ['instant', 'instant prioritas'];

    public static function needsManualDriverDispatch(
        ?string $courierName,
        ?string $shippingProvider = null,
        ?string $shippingType = null,
    ): bool {
        foreach ([$courierName, $shippingProvider] as $name) {
            if ($name === null || $name === '') {
                continue;
            }

            if (preg_match('/'.self::MANUAL_DRIVER_REGEX.'/i', $name)) {
                return true;
            }

            if (in_array(strtolower(trim($name)), self::MANUAL_DRIVER_UMBRELLA, true)) {
                return true;
            }
        }

        return $shippingType !== null
            && preg_match('/'.self::MANUAL_DRIVER_REGEX.'/i', $shippingType) === 1;
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
