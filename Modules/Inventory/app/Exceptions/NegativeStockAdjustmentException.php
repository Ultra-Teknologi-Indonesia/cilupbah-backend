<?php

namespace Modules\Inventory\Exceptions;

use RuntimeException;

final class NegativeStockAdjustmentException extends RuntimeException
{
    public function __construct(
        public readonly int $systemQty,
        public readonly int $adjustmentQty,
        public readonly int $actualQty,
    ) {
        parent::__construct(
            'Stok tidak mencukupi. Adjustment akan menyebabkan stok minus '
            ."(on_hand: {$systemQty}, adjustment: {$adjustmentQty}, hasil: {$actualQty})."
        );
    }
}
