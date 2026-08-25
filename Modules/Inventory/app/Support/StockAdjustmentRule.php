<?php

namespace Modules\Inventory\Support;

use InvalidArgumentException;
use Modules\Inventory\Exceptions\NegativeStockAdjustmentException;

final class StockAdjustmentRule
{
    public const MODE_DELTA = 'DELTA';

    public const MODE_FINAL = 'FINAL';

    public function calculate(
        mixed $systemQty,
        mixed $inputValue,
        string $mode,
    ): StockAdjustmentCalculation {
        $normalizedMode = strtoupper(trim($mode));
        if (! in_array($normalizedMode, [self::MODE_DELTA, self::MODE_FINAL], true)) {
            throw new InvalidArgumentException("Mode adjustment '{$mode}' tidak valid.");
        }

        $system = $this->parseInteger($systemQty, 'system_qty');
        $input = $this->parseInteger($inputValue, $normalizedMode === self::MODE_DELTA ? 'delta_qty' : 'final_qty');

        if ($normalizedMode === self::MODE_FINAL && $input < 0) {
            throw new InvalidArgumentException('Nilai akhir (final_qty) tidak boleh kurang dari 0.');
        }

        $actual = $normalizedMode === self::MODE_DELTA
            ? $system + $input
            : $input;
        $difference = $actual - $system;

        $this->assertAllowed($system, $difference, $actual);

        return new StockAdjustmentCalculation(
            mode: $normalizedMode,
            systemQty: $system,
            inputValue: $input,
            actualQty: $actual,
            differenceQty: $difference,
        );
    }

    public function parseInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if (preg_match('/^[+-]?\d+$/', $normalized) === 1) {
                return (int) $normalized;
            }
        }

        throw new InvalidArgumentException("{$field} harus berupa bilangan bulat.");
    }

    public function negativeStockAllowed(): bool
    {
        return (bool) config('inventory.allow_negative_stock', true);
    }

    public function assertAllowed(int $systemQty, int $differenceQty, int $actualQty): void
    {

        if ($this->negativeStockAllowed() || $differenceQty >= 0 || $actualQty >= 0) {
            return;
        }

        throw new NegativeStockAdjustmentException(
            systemQty: $systemQty,
            adjustmentQty: $differenceQty,
            actualQty: $actualQty,
        );
    }
}
