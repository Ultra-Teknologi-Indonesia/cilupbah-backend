<?php

namespace Modules\Inventory\Support;

final readonly class StockAdjustmentCalculation
{
    public function __construct(
        public string $mode,
        public int $systemQty,
        public int $inputValue,
        public int $actualQty,
        public int $differenceQty,
    ) {}

    public function resultsInNegativeStock(): bool
    {
        return $this->actualQty < 0;
    }
}
