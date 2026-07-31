<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Jobs\RespondBuyerCancellationJob;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class RespondBuyerCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(string $source, array $overrides = []): array
    {
        $id = Str::uuid()->toString();
        $orderNo = 'TT-' . substr($id, 0, 6);
        DB::table('sales_orders')->insert(array_merge([
            'id' => $id,
            'salesorder_no' => 'SO-' . substr($id, 0, 6),
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
        [$id, $orderNo] = $this->seedOrder('shopee');

        $this->mock(ShopeeOrderService::class, function ($m) use ($orderNo) {
            $m->shouldReceive('handleBuyerCancellation')->once()->with('SHOP-123', $orderNo, 'ACCEPT')->andReturn([]);
        });

        (new RespondBuyerCancellationJob($id, RespondBuyerCancellationJob::ACCEPT))->handle();
    }

    public function test_shopee_reject_calls_handle_buyer_cancellation_reject(): void
    {
        [$id, $orderNo] = $this->seedOrder('shopee');

        $this->mock(ShopeeOrderService::class, function ($m) use ($orderNo) {
            $m->shouldReceive('handleBuyerCancellation')->once()->with('SHOP-123', $orderNo, 'REJECT')->andReturn([]);
        });

        (new RespondBuyerCancellationJob($id, RespondBuyerCancellationJob::REJECT))->handle();
    }

    public function test_tiktok_accept_and_reject_call_correct_endpoints(): void
    {
        [$idA, $orderNoA] = $this->seedOrder('tiktok');
        [$idR, $orderNoR] = $this->seedOrder('tiktok');

        $this->mock(TikTokOrderService::class, function ($m) use ($orderNoA, $orderNoR) {
            $m->shouldReceive('acceptBuyerCancellation')->once()->with('SHOP-123', $orderNoA)->andReturn([]);
            $m->shouldReceive('rejectBuyerCancellation')->once()->with('SHOP-123', $orderNoR)->andReturn([]);
        });

        (new RespondBuyerCancellationJob($idA, RespondBuyerCancellationJob::ACCEPT))->handle();
        (new RespondBuyerCancellationJob($idR, RespondBuyerCancellationJob::REJECT))->handle();
    }

    public function test_lazada_does_not_call_any_buyer_cancel_api(): void
    {
        [$id] = $this->seedOrder('lazada');

        $this->mock(ShopeeOrderService::class, fn ($m) => $m->shouldNotReceive('handleBuyerCancellation'));
        $this->mock(TikTokOrderService::class, function ($m) {
            $m->shouldNotReceive('acceptBuyerCancellation');
            $m->shouldNotReceive('rejectBuyerCancellation');
        });

        (new RespondBuyerCancellationJob($id, RespondBuyerCancellationJob::ACCEPT))->handle();

        $this->assertTrue(true); // selesai tanpa memanggil API channel
    }

    public function test_reject_cancel_request_now_notifies_channel(): void
    {
        Bus::fake();
        [$id] = $this->seedOrder('shopee');

        app(SalesOrderService::class)->rejectCancelRequest($id, 'stok sudah disiapkan');

        Bus::assertDispatched(
            RespondBuyerCancellationJob::class,
            fn (RespondBuyerCancellationJob $job) => $job->orderId === $id && $job->decision === RespondBuyerCancellationJob::REJECT,
        );
    }
}
