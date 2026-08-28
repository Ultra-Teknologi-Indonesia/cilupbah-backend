<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Unit;

use Modules\Inventory\Support\MovingAverageCost;
use PHPUnit\Framework\TestCase;

final class MovingAverageCostTest extends TestCase
{
    public function test_it_calculates_a_normal_weighted_average(): void
    {
        $this->assertSame(1100.0, MovingAverageCost::afterReceipt(10, 1000, 10, 1200));
    }

    public function test_a_receipt_after_negative_stock_establishes_a_fresh_cost(): void
    {
        $this->assertSame(1000.0, MovingAverageCost::afterReceipt(-5, 250, 2, 1000));
    }

    public function test_a_zero_cost_receipt_keeps_the_existing_non_negative_cost(): void
    {
        $this->assertSame(250.0, MovingAverageCost::afterReceipt(5, 250, 2, 0));
        $this->assertSame(0.0, MovingAverageCost::afterReceipt(5, -10, 2, 0));
    }
}
