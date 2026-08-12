<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Support\ShadowOrderGuard;
use Tests\TestCase;

class ShadowOrderIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'salesorder_no'    => 'SHW-' . uniqid(),
            'channel_order_no' => 'CO-' . uniqid(),
            'channel_shop_id'  => 'shop-1',
            'customer_name'    => 'Buyer',
            'source'           => 'shopee',
            'channel_status'   => 'COMPLETED',
            'status'           => 'shipped',
            'sub_total'        => 100000,
            'total_disc'       => 0,
            'total_tax'        => 0,
            'shipping_cost'    => 0,
            'insurance_cost'   => 0,
            'grand_total'      => 100000,
            'is_paid'          => true,
            'is_settled'       => false,
        ], $overrides));
    }

    public function test_shadow_orders_are_excluded_from_finance_sync(): void
    {
        Queue::fake();

        $real = $this->makeOrder(['is_shadow' => false]);
        $this->makeOrder(['is_shadow' => true]);

        $this->artisan('orders:sync-finance')->assertSuccessful();

        Queue::assertPushed(SyncOrderFinanceJob::class, 1);
        Queue::assertPushed(
            SyncOrderFinanceJob::class,
            fn (SyncOrderFinanceJob $job) => $job->orderId === $real->id,
        );
    }

    public function test_exclude_shadow_scope_keeps_orders_without_flag(): void
    {
        $real = $this->makeOrder(['is_shadow' => false]);
        $shadow = $this->makeOrder(['is_shadow' => true]);

        $ids = SalesOrder::query()->excludeShadow()->pluck('id')->all();

        $this->assertContains($real->id, $ids);
        $this->assertNotContains($shadow->id, $ids);

        $shadowIds = SalesOrder::query()->onlyShadow()->pluck('id')->all();

        $this->assertSame([$shadow->id], $shadowIds);
    }

    public function test_guard_blocks_shadow_orders_only(): void
    {
        $this->assertTrue(ShadowOrderGuard::blocks($this->makeOrder(['is_shadow' => true]), 'reserve_stock'));
        $this->assertFalse(ShadowOrderGuard::blocks($this->makeOrder(['is_shadow' => false]), 'reserve_stock'));
    }

    public function test_only_open_shadow_orders_are_promotable(): void
    {
        $this->assertTrue(SalesOrderService::isPromotableFromShadow(
            $this->makeOrder(['is_shadow' => true, 'status' => 'pending']),
        ));

        $this->assertTrue(SalesOrderService::isPromotableFromShadow(
            $this->makeOrder(['is_shadow' => true, 'status' => 'reserved']),
        ));

        $this->assertFalse(SalesOrderService::isPromotableFromShadow(
            $this->makeOrder(['is_shadow' => true, 'status' => 'shipped']),
        ));

        $this->assertFalse(SalesOrderService::isPromotableFromShadow(
            $this->makeOrder(['is_shadow' => true, 'status' => 'pending', 'is_canceled' => true]),
        ));
    }

    public function test_promote_clears_shadow_flag(): void
    {
        $order = $this->makeOrder(['is_shadow' => true, 'status' => 'pending']);

        $this->assertTrue(app(SalesOrderService::class)->promoteFromShadow($order));
        $this->assertFalse((bool) $order->fresh()->is_shadow);
    }

    public function test_promote_refuses_orders_that_are_already_processed(): void
    {
        $order = $this->makeOrder(['is_shadow' => true, 'status' => 'shipped']);

        $this->assertFalse(app(SalesOrderService::class)->promoteFromShadow($order));
        $this->assertTrue((bool) $order->fresh()->is_shadow);
    }
}
