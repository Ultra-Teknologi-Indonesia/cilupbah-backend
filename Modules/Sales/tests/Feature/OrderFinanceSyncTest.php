<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Exceptions\TikTokApiException;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Support\TikTokErrorCatalog;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class OrderFinanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'salesorder_no' => 'FIN-'.uniqid(),
            'channel_order_no' => 'CO-'.uniqid(),
            'channel_shop_id' => 'shop-1',
            'customer_name' => 'Buyer',
            'source' => 'shopee',
            'channel_status' => 'COMPLETED',
            'status' => 'shipped',
            'sub_total' => 100000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 100000,
            'is_paid' => true,
        ], $overrides));
    }

    private function finance(bool $settled = true): array
    {
        return [
            'seller_voucher' => 5000,
            'platform_voucher' => 3000,
            'commission_fee' => 2200,
            'service_fee' => 1100,
            'settlement_amount' => 98900,
            'fee_currency' => 'IDR',
            'is_settled' => $settled,

            'settled_at' => $settled ? now()->toDateTimeString() : null,
            'fee_lines' => [
                ['fee_type' => 'commission_fee', 'channel_fee_code' => 'commission_fee', 'amount' => 2200],
                ['fee_type' => 'platform_voucher', 'channel_fee_code' => 'voucher_from_shopee', 'amount' => 3000],
            ],
        ];
    }

    public function test_update_finance_writes_columns_and_fee_lines_without_touching_status(): void
    {
        $service = app(SalesOrderService::class);
        $order = $this->makeOrder(['status' => 'shipped']);

        $service->updateOrderFinance($order->id, $this->finance());

        $fresh = $order->fresh();
        $this->assertSame('shipped', $fresh->status, 'status tidak boleh berubah oleh sync keuangan');
        $this->assertEquals(2200, $fresh->commission_fee);
        $this->assertEquals(98900, $fresh->settlement_amount);
        $this->assertTrue((bool) $fresh->is_settled);
        $this->assertNotNull($fresh->finance_synced_at);
        $this->assertSame(2, DB::table('sales_order_fee_lines')->where('order_id', $order->id)->count());
    }

    public function test_update_finance_normalizes_empty_money_and_invalid_settlement_uuid(): void
    {
        $order = $this->makeOrder();

        app(SalesOrderService::class)->updateOrderFinance($order->id, [
            'total_tax' => null,
            'insurance_cost' => '',
            'commission_fee' => '-',
            'channel_settlement_id' => '-',
        ]);

        $fresh = $order->fresh();
        $this->assertEquals(0, $fresh->total_tax);
        $this->assertEquals(0, $fresh->insurance_cost);
        $this->assertEquals(0, $fresh->commission_fee);
        $this->assertNull($fresh->channel_settlement_id);
    }

    public function test_channel_order_upsert_normalizes_missing_required_money(): void
    {
        $salesOrderNo = 'FIN-UP-'.uniqid();

        app(SalesOrderRepository::class)->upsertOrderBySalesOrderNo($salesOrderNo, [
            'salesorder_no' => $salesOrderNo,
            'channel_order_no' => 'CO-'.uniqid(),
            'channel_shop_id' => 'shop-1',
            'customer_name' => 'Buyer',
            'transaction_date' => now(),
            'sub_total' => 100000,
            'total_disc' => null,
            'shipping_cost' => null,
            'grand_total' => 100000,
            'status' => 'shipped',
            'is_paid' => true,
            'payment_method' => null,
            'source' => 'shopee',
        ]);

        $stored = SalesOrder::where('salesorder_no', $salesOrderNo)->firstOrFail();
        $this->assertEquals(0, $stored->total_disc);
        $this->assertEquals(0, $stored->total_tax);
        $this->assertEquals(0, $stored->shipping_cost);
        $this->assertEquals(0, $stored->insurance_cost);
    }

    public function test_settlement_estimate_without_settled_at_is_not_cair(): void
    {
        $service = app(SalesOrderService::class);
        $order = $this->makeOrder();

        $service->updateOrderFinance($order->id, $this->finance(settled: false));

        $fresh = $order->fresh();
        $this->assertEquals(98900, $fresh->settlement_amount, 'estimasi tetap tersimpan');
        $this->assertNull($fresh->settled_at);
        $this->assertFalse((bool) $fresh->is_settled, 'tanpa settled_at tidak boleh cair');
    }

    public function test_canceled_order_is_never_cair(): void
    {
        $service = app(SalesOrderService::class);
        $order = $this->makeOrder();
        $order->forceFill(['is_canceled' => true])->save();

        $service->updateOrderFinance($order->id, $this->finance(settled: true));

        $this->assertFalse((bool) $order->fresh()->is_settled, 'pesanan batal tak pernah cair');
    }

    public function test_update_finance_is_idempotent_fee_lines_replaced_not_appended(): void
    {
        $service = app(SalesOrderService::class);
        $order = $this->makeOrder();

        $service->updateOrderFinance($order->id, $this->finance());
        $service->updateOrderFinance($order->id, $this->finance());

        $this->assertSame(2, DB::table('sales_order_fee_lines')->where('order_id', $order->id)->count());
    }

    public function test_settled_finance_is_not_clobbered_by_order_repull(): void
    {
        $service = app(SalesOrderService::class);
        $repo = app(SalesOrderRepository::class);
        $order = $this->makeOrder();

        $service->updateOrderFinance($order->id, $this->finance(settled: true));
        $this->assertEquals(5000, $order->fresh()->seller_voucher);

        $repo->upsertOrderBySalesOrderNo($order->salesorder_no, [
            'salesorder_no' => $order->salesorder_no,
            'channel_order_no' => $order->channel_order_no,
            'channel_shop_id' => 'shop-1',
            'customer_name' => 'Buyer',
            'transaction_date' => now(),
            'sub_total' => 100000,
            'total_disc' => 0,
            'seller_voucher' => 999,
            'platform_voucher' => 999,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 100000,
            'channel_status' => 'COMPLETED',
            'status' => 'shipped',
            'is_paid' => true,
            'payment_method' => null,
            'source' => 'shopee',
        ]);

        $this->assertEquals(5000, $order->fresh()->seller_voucher, 'voucher final tidak boleh ditimpa estimasi re-pull');
    }

    public function test_sweep_dispatches_only_completed_and_unsettled_marketplace_orders(): void
    {
        Queue::fake();

        $target = $this->makeOrder(['channel_status' => 'COMPLETED', 'is_settled' => false]);
        $this->makeOrder(['channel_status' => 'COMPLETED', 'is_settled' => true]);
        $this->makeOrder(['channel_status' => 'SHIPPED', 'is_settled' => false]);
        $this->makeOrder(['channel_status' => 'COMPLETED', 'is_settled' => false, 'source' => 'manual']);

        $this->artisan('orders:sync-finance')->assertSuccessful();

        Queue::assertPushed(SyncOrderFinanceJob::class, 1);
        Queue::assertPushed(SyncOrderFinanceJob::class, fn ($job) => $job->orderId === $target->id);
    }

    public function test_sync_job_handles_tiktok_rate_limit_36009002_gracefully(): void
    {
        $order = $this->makeOrder([
            'source' => 'tiktok',
            'channel_status' => 'COMPLETED',
            'channel_shop_id' => 'tt-shop-1',
            'channel_order_no' => '584950618786072357',
            'is_settled' => false,
        ]);

        $mockTikTok = $this->createMock(TikTokOrderService::class);
        $mockTikTok->method('getOrderStatement')->willThrowException(
            new TikTokApiException(
                '36009002',
                TikTokErrorCatalog::RETRYABLE,
                'Terlalu banyak permintaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.',
                'Too many requests for downstream.'
            )
        );
        $this->app->instance(TikTokOrderService::class, $mockTikTok);

        $job = new SyncOrderFinanceJob($order->id);

        $job->handle(app(SalesOrderService::class));
        $this->assertTrue(true);
    }
}
