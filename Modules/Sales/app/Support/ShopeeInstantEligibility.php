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

        return $order->is_instant;
    }

    public static function matchesInstantOrSameDay(string $shippingType, string $shippingProvider): bool
    {
        $type = strtoupper($shippingType);
        if (in_array($type, ['INSTANT', 'SAME_DAY', 'SAMEDAY'], true)) {
            return true;
        }

        return (bool) preg_match('/instant|instan|same[- ]?day/i', $shippingType);
    }
}
