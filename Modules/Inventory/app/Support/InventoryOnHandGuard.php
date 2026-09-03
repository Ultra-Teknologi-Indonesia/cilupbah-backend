<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Modules\Inventory\Exceptions\NegativeOnHandException;

final class InventoryOnHandGuard
{
    public function resultAfterDelta(
        int $currentOnHand,
        int $delta,
        string $operation,
    ): int {
        $result = $currentOnHand + $delta;

        if ($result < 0) {
            throw new NegativeOnHandException($currentOnHand, $delta, $operation);
        }

        return $result;
    }

    public function assertNonNegative(int $onHand, string $operation = 'Perubahan stok'): void
    {
        if ($onHand < 0) {
            throw new NegativeOnHandException($onHand, 0, $operation);
        }
    }
}
