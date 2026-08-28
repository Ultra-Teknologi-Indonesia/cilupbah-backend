<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

final class MovingAverageCost
{
    public static function afterReceipt(
        float $quantityBefore,
        float $averageCostBefore,
        float $receivedQuantity,
        float $receivedCostPerUnit,
    ): float {
        $safeCurrentCost = max(0.0, $averageCostBefore);
        $safeReceiptCost = max(0.0, $receivedCostPerUnit);

        if ($receivedQuantity <= 0 || $safeReceiptCost <= 0) {
            return $safeCurrentCost;
        }

        if ($quantityBefore <= 0) {
            return round($safeReceiptCost, 2);
        }

        $quantityAfter = $quantityBefore + $receivedQuantity;
        if ($quantityAfter <= 0) {
            return round($safeReceiptCost, 2);
        }

        return round(max(0.0, (
            ($quantityBefore * $safeCurrentCost)
            + ($receivedQuantity * $safeReceiptCost)
        ) / $quantityAfter), 2);
    }
}
