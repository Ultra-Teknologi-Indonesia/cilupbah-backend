<?php

namespace Modules\Sales\Tests\Feature;

use App\Exceptions\UserFacingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Jobs\CancelChannelOrderJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class RequestChannelCancelTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(array $overrides = []): string
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kat RC', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId, 'category_id' => $categoryId, 'name' => 'Produk RC',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId, 'product_id' => $productId, 'sku' => 'SKU-RC-' . substr($variantId, 0, 8),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert(array_merge([
            'id' => $orderId,
            'salesorder_no' => 'SO-RC-' . substr($orderId, 0, 6),
            'customer_name' => 'Buyer',
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-1',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_status_raw' => 'READY_TO_SHIP',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $orderId;
    }

    public function test_shopee_request_cancel_sets_pending_and_dispatches_job(): void
    {
        Bus::fake();
        $orderId = $this->seedOrder();

        $order = app(SalesOrderService::class)->requestChannelCancel($orderId, 'OUT_OF_STOCK');

        $this->assertSame('pending', $order->channel_cancel_status);
        $this->assertSame('OUT_OF_STOCK', $order->cancel_reason);
        $this->assertNotNull($order->channel_cancel_requested_at);

        $this->assertSame('reserved', $order->status);

        Bus::assertDispatched(CancelChannelOrderJob::class,
            fn ($job) => (fn () => $this->orderId)->call($job) === $orderId
                && (fn () => $this->cancelReason)->call($job) === 'OUT_OF_STOCK');
    }

    public function test_invalid_reason_rejected(): void
    {
        Bus::fake();
        $orderId = $this->seedOrder();

        $this->expectException(UserFacingException::class);
        app(SalesOrderService::class)->requestChannelCancel($orderId, 'REASON_NGACO');
    }

    public function test_shipped_local_status_blocked(): void
    {
        Bus::fake();
        $orderId = $this->seedOrder(['status' => 'shipped', 'channel_status' => 'SHIPPED', 'channel_status_raw' => 'SHIPPED']);

        $this->expectException(UserFacingException::class);
        app(SalesOrderService::class)->requestChannelCancel($orderId, 'OUT_OF_STOCK');
    }

    public function test_ineligible_channel_status_blocked(): void
    {
        Bus::fake();

        $orderId = $this->seedOrder([
            'source' => 'lazada',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_status_raw' => 'ready_to_ship',
        ]);

        $this->expectException(UserFacingException::class);
        app(SalesOrderService::class)->requestChannelCancel($orderId, '123');
    }

    public function test_tiktok_reason_is_status_aware(): void
    {
        Bus::fake();

        $paidOrder = $this->seedOrder([
            'source' => 'tiktok',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_status_raw' => 'ON_HOLD',
        ]);
        $order = app(SalesOrderService::class)->requestChannelCancel($paidOrder, 'seller_cancel_reason_out_of_stock');
        $this->assertSame('pending', $order->channel_cancel_status);

        $paidOrder2 = $this->seedOrder([
            'source' => 'tiktok',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_status_raw' => 'ON_HOLD',
        ]);
        $this->expectException(UserFacingException::class);
        app(SalesOrderService::class)->requestChannelCancel($paidOrder2, 'seller_cancel_unpaid_reason_out_of_stock');
    }

    public function test_tiktok_paid_order_without_raw_uses_is_paid_for_reason_group(): void
    {
        Bus::fake();

        $paidNoRaw = $this->seedOrder([
            'source' => 'tiktok',
            'status' => 'reserved',
            'channel_status' => 'UNPAID',
            'channel_status_raw' => null,
            'is_paid' => true,
        ]);
        $order = app(SalesOrderService::class)->requestChannelCancel($paidNoRaw, 'seller_cancel_reason_out_of_stock');
        $this->assertSame('pending', $order->channel_cancel_status);

        $paidNoRaw2 = $this->seedOrder([
            'source' => 'tiktok',
            'status' => 'reserved',
            'channel_status' => 'UNPAID',
            'channel_status_raw' => null,
            'is_paid' => true,
        ]);
        $this->expectException(UserFacingException::class);
        app(SalesOrderService::class)->requestChannelCancel($paidNoRaw2, 'seller_cancel_unpaid_reason_out_of_stock');
    }

    public function test_move_to_ready_holds_pending_cancel_and_release_unblocks(): void
    {
        Bus::fake();
        $orderId = $this->seedOrder([
            'source' => 'tiktok',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_cancel_status' => 'pending',
            'channel_cancel_requested_at' => now(),
        ]);

        $res = app(SalesOrderService::class)->moveToReadyToProcess([$orderId]);
        $this->assertSame(0, $res['moved']);
        $this->assertCount(1, $res['skipped']);
        $this->assertSame('cancel_pending', $res['skipped'][0]['reason']);

        $order = app(SalesOrderService::class)->releaseChannelCancel($orderId);
        $this->assertNull($order->channel_cancel_status);
        $this->assertNull($order->channel_cancel_requested_at);
    }

    public function test_failed_cancel_is_not_held_from_processing(): void
    {

        $orderId = $this->seedOrder([
            'source' => 'tiktok',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_cancel_status' => 'failed',
        ]);

        $held = SalesOrder::whereIn('id', [$orderId])
            ->where('channel_cancel_status', 'pending')
            ->exists();
        $this->assertFalse($held);
    }

    public function test_unknown_channel_status_allowed_when_local_pre_ship(): void
    {
        Bus::fake();

        $orderId = $this->seedOrder([
            'source' => 'shopee',
            'status' => 'reserved',
            'channel_status' => 'UNKNOWN',
            'channel_status_raw' => null,
            'is_paid' => true,
        ]);

        $order = app(SalesOrderService::class)->requestChannelCancel($orderId, 'OUT_OF_STOCK');
        $this->assertSame('pending', $order->channel_cancel_status);
    }

    public function test_mark_channel_cancel_rejected_flips_pending_to_failed(): void
    {
        $orderId = $this->seedOrder([
            'source' => 'tiktok',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_cancel_status' => 'pending',
            'channel_cancel_requested_at' => now(),
        ]);
        $no = SalesOrder::find($orderId)->salesorder_no;

        app(SalesOrderService::class)->markChannelCancelRejected($no, 'ditolak');

        $order = SalesOrder::find($orderId);
        $this->assertSame('failed', $order->channel_cancel_status);
        $this->assertSame('ditolak', $order->channel_cancel_error);
    }

    public function test_double_request_blocked(): void
    {
        Bus::fake();
        $orderId = $this->seedOrder(['channel_cancel_status' => 'pending']);

        $this->expectException(UserFacingException::class);
        app(SalesOrderService::class)->requestChannelCancel($orderId, 'OUT_OF_STOCK');
    }
}
