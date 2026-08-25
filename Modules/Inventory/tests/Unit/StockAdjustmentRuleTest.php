<?php

namespace Modules\Inventory\Tests\Unit;

use InvalidArgumentException;
use Modules\Inventory\Exceptions\NegativeStockAdjustmentException;
use Modules\Inventory\Support\StockAdjustmentRule;
use Tests\TestCase;

class StockAdjustmentRuleTest extends TestCase
{
    public function test_delta_and_final_use_the_same_calculation_contract(): void
    {
        $rule = app(StockAdjustmentRule::class);

        $delta = $rule->calculate(42, 8, StockAdjustmentRule::MODE_DELTA);
        $final = $rule->calculate(42, 50, StockAdjustmentRule::MODE_FINAL);

        $this->assertSame(50, $delta->actualQty);
        $this->assertSame(8, $delta->differenceQty);
        $this->assertSame(50, $final->actualQty);
        $this->assertSame(8, $final->differenceQty);
    }

    public function test_positive_delta_can_recover_negative_stock_when_negative_stock_is_disallowed(): void
    {
        config(['inventory.allow_negative_stock' => false]);

        $result = app(StockAdjustmentRule::class)->calculate(-158, 1, StockAdjustmentRule::MODE_DELTA);

        $this->assertSame(-157, $result->actualQty);
        $this->assertSame(1, $result->differenceQty);
    }

    public function test_negative_delta_is_allowed_and_reported_when_policy_allows_negative_stock(): void
    {
        config(['inventory.allow_negative_stock' => true]);

        $result = app(StockAdjustmentRule::class)->calculate(-53, -2, StockAdjustmentRule::MODE_DELTA);

        $this->assertSame(-55, $result->actualQty);
        $this->assertTrue($result->resultsInNegativeStock());
    }

    public function test_negative_delta_is_rejected_when_policy_disallows_negative_stock(): void
    {
        config(['inventory.allow_negative_stock' => false]);

        $this->expectException(NegativeStockAdjustmentException::class);
        $this->expectExceptionMessage('on_hand: 5, adjustment: -10, hasil: -5');

        app(StockAdjustmentRule::class)->calculate(5, -10, StockAdjustmentRule::MODE_DELTA);
    }

    public function test_final_quantity_is_always_non_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('final_qty');

        app(StockAdjustmentRule::class)->calculate(-5, -1, StockAdjustmentRule::MODE_FINAL);
    }

    public function test_malformed_import_quantity_is_not_silently_coerced_to_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(StockAdjustmentRule::class)->parseInteger('not-a-number', 'delta_qty');
    }
}
