<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

final class SalesOrderStatusConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_is_accepted_by_sales_order_status_constraint(): void
    {
        $order = SalesOrder::factory()->create();

        $order->update(['status' => 'returned']);

        self::assertSame('returned', $order->fresh()->status);
    }
}
