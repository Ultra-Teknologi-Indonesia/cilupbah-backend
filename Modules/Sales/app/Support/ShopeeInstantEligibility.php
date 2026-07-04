<?php

namespace Modules\Sales\Support;

use Modules\Sales\Models\SalesOrder;

class ShopeeInstantEligibility
{
    public static function isEligible(SalesOrder $order): bool
    {
        if (strtolower((string) $order->source) !== 'shopee') {
            return false;
        }

        return self::matchesInstantOrSameDay(
            (string) $order->shipping_type,
            (string) $order->shipping_provider,
        );
    }

    public static function matchesInstantOrSameDay(string $shippingType, string $shippingProvider): bool
    {
        $type = strtoupper($shippingType);
        if (in_array($type, ['INSTANT', 'SAME_DAY', 'SAMEDAY'], true)) {
            return true;
        }

        $haystack = strtolower($shippingType . ' ' . $shippingProvider);

        return (bool) preg_match('/instant|instan|same[- ]?day/i', $haystack);
    }
}
