<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Jobs\RespondBuyerCancellationJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class RespondBuyerCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_cancellation_job_uses_dedicated_queue_and_is_unique_per_decision(): void
    {
        $job = new RespondBuyerCancellationJob('order-1', RespondBuyerCancellationJob::ACCEPT);

        $this->assertSame('channel-cancellation', $job->queue);
        $this->assertSame('order-1:accept', $job->uniqueId());
        $this->assertSame(900, $job->uniqueFor);
    }

    private function seedOrder(string $source, array $overrides = []): array
    {
        $id = Str::uuid()->toString();
        $orderNo = 'TT-'.substr($id, 0, 6);
        DB::table('sales_orders')->insert(array_merge([
            'id' => $id,
            'salesorder_no' => 'SO-'.substr($id, 0, 6),
            'channel_order_no' => $orderNo,
            'channel_shop_id' => 'SHOP-123',
            'customer_name' => 'Buyer',
            'source' => $source,
            'status' => 'packed',
            'is_canceled' => false,
            'cancel_requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return [$id, $orderNo];
    }

    public function test_shopee_accept_calls_handle_buyer_cancellation_accept(): void
    {
        [$id, $orderNo] = $this->seedOrder('shopee', ['cancel_accepted_at' => now()]);

        $this->mock(ShopeeOrderService::class, function ($m) use ($orderNo) {
            $m->shouldReceive('handleBuyerCancellation')->once()->with('SHOP-123', $orderNo, 'ACCEPT')->andReturn([]);
        });

        (new RespondBuyerCancellationJob($id, RespondBuyerCancellationJob::ACCEPT))->handle();
    }

    public function test_shopee_reject_calls_handle_buyer_cancellation_reject(): void
    {
        [$id, $orderNo] = $this->seedOrder('shopee', ['cancel_rejected_at' => now()]);

        $this->mock(ShopeeOrderService::class, function ($m) use ($orderNo) {
            $m->shouldReceive('handleBuyerCancellation')->once()->with('SHOP-123', $orderNo, 'REJECT')->andReturn([]);
        });

        (new RespondBuyerCancellationJob($id, RespondBuyerCancellationJob::REJECT))->handle();
    }

    public function test_tiktok_accept_and_reject_call_correct_endpoints(): void
    {
        [$idA, $orderNoA] = $this->seedOrder('tiktok', ['cancel_accepted_at' => now()]);
        [$idR, $orderNoR] = $this->seedOrder('tiktok', ['cancel_rejected_at' => now()]);

        $this->mock(TikTokOrderService::class, function ($m) use ($orderNoA, $orderNoR) {
            $m->shouldReceive('acceptBuyerCancellation')->once()->with('SHOP-123', $orderNoA)->andReturn([]);
            $m->shouldReceive('rejectBuyerCancellation')->once()->with('SHOP-123', $orderNoR)->andReturn([]);
        });

        (new RespondBuyerCancellationJob($idA, RespondBuyerCancellationJob::ACCEPT))->handle();
        (new RespondBuyerCancellationJob($idR, RespondBuyerCancellationJob::REJECT))->handle();
    }

    public function test_lazada_accept_calls_buyer_cancellation_api_and_records_success(): void
    {
        [$id, $orderNo] = $this->seedOrder('lazada', ['cancel_accepted_at' => now()]);

        $this->mock(ShopeeOrderService::class, fn ($m) => $m->shouldNotReceive('handleBuyerCancellation'));
        $this->mock(TikTokOrderService::class, function ($m) {
            $m->shouldNotReceive('acceptBuyerCancellation');
            $m->shouldNotReceive('rejectBuyerCancellation');
        });
        $this->mock(LazadaOrderService::class, function ($m) use ($orderNo) {
            $m->shouldReceive('respondBuyerCancellation')
                ->once()
                ->with('SHOP-123', $orderNo, RespondBuyerCancellationJob::ACCEPT, null, null)
                ->andReturn(['handled' => true]);
        });

        (new RespondBuyerCancellationJob($id, RespondBuyerCancellationJob::ACCEPT))->handle();

        $order = SalesOrder::findOrFail($id);
        $this->assertSame('succeeded', $order->buyer_cancel_sync_status);
        $this->assertNotNull($order->buyer_cancel_synced_at);
    }

    public function test_channel_failure_is_visible_and_is_rethrowable_for_retry(): void
    {
        [$id, $orderNo] = $this->seedOrder('shopee', ['cancel_accepted_at' => now()]);

        $this->mock(ShopeeOrderService::class, function ($m) use ($orderNo) {
            $m->shouldReceive('handleBuyerCancellation')
                ->once()
                ->with('SHOP-123', $orderNo, 'ACCEPT')
                ->andThrow(new \RuntimeException('Channel timeout'));
        });

        $this->expectException(\RuntimeException::class);
        try {
            (new RespondBuyerCancellationJob($id, RespondBuyerCancellationJob::ACCEPT))->handle();
        } finally {
            $this->assertSame('failed', SalesOrder::findOrFail($id)->buyer_cancel_sync_status);
            $this->assertSame('Channel timeout', SalesOrder::findOrFail($id)->buyer_cancel_sync_error);
        }
    }

    public function test_reject_cancel_request_responds_to_channel_synchronously(): void
    {
        [$id, $orderNo] = $this->seedOrder('shopee');

        $this->mock(ShopeeOrderService::class, function ($m) use ($orderNo) {
            $m->shouldReceive('handleBuyerCancellation')
                ->once()
                ->with('SHOP-123', $orderNo, 'REJECT')
                ->andReturn([]);
        });

        app(SalesOrderService::class)->rejectCancelRequest($id, 'stok sudah disiapkan');

        $this->assertNotNull(SalesOrder::query()->findOrFail($id)->cancel_rejected_at);
    }
}
