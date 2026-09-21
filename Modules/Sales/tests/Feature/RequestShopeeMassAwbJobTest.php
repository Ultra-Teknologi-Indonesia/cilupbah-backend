<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Jobs\RequestShopeeMassAwbJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use Tests\TestCase;

final class RequestShopeeMassAwbJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_and_reads_multiple_awbs_without_falling_back_to_single_jobs(): void
    {
        Queue::fake();

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-MASS-AWB',
            'shop_name' => 'Shopee Mass AWB',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
            'fulfillment_push_enabled' => true,
        ]);

        $batch = BulkShippingLabelBatch::create([
            'user_id' => User::factory()->create()->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'per_channel_opts' => [],
            'total_count' => 2,
            'done_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
        ]);

        $first = $this->createWaitingOrder($batch, 'ORDER-MASS-A', 'PKG-A');
        $second = $this->createWaitingOrder($batch, 'ORDER-MASS-B', 'PKG-B');

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('resolveMassPackages')
            ->once()
            ->with('SHOP-MASS-AWB', ['ORDER-MASS-A', 'ORDER-MASS-B'])
            ->andReturn([
                'ORDER-MASS-A' => [[
                    'package_number' => 'PKG-A',
                    'logistics_channel_id' => 8001,
                    'product_location_id' => 'LOC-1',
                ]],
                'ORDER-MASS-B' => [[
                    'package_number' => 'PKG-B',
                    'logistics_channel_id' => 8001,
                    'product_location_id' => 'LOC-1',
                ]],
            ]);
        $shopee->shouldReceive('getMassTrackingNumbers')
            ->twice()
            ->with('SHOP-MASS-AWB', ['PKG-A', 'PKG-B'])
            ->andReturn(
                ['results' => [
                    'PKG-A' => ['tracking_number' => null],
                    'PKG-B' => ['tracking_number' => null],
                ]],
                ['results' => [
                    'PKG-A' => ['tracking_number' => 'SPX-A', 'pickup_code' => '1111'],
                    'PKG-B' => ['tracking_number' => 'SPX-B', 'pickup_code' => '2222'],
                ]],
            );
        $shopee->shouldReceive('massShipPackages')
            ->once()
            ->with('SHOP-MASS-AWB', ['PKG-A', 'PKG-B'], [
                'logistics_channel_id' => 8001,
                'product_location_id' => 'LOC-1',
            ])
            ->andReturn(['results' => [
                'PKG-A' => ['shipped' => true, 'error' => null],
                'PKG-B' => ['shipped' => true, 'error' => null],
            ]]);
        $this->app->instance(ShopeeOrderService::class, $shopee);

        (new RequestShopeeMassAwbJob(
            (string) $batch->id,
            'SHOP-MASS-AWB',
            [(string) $first->id, (string) $second->id],
        ))->handle(
            $shopee,
            app(BulkShippingLabelService::class),
            app(ShippingLabelPreparationDispatcher::class),
        );

        $this->assertSame('SPX-A', $first->fresh()->tracking_number);
        $this->assertSame('SPX-B', $second->fresh()->tracking_number);
        $this->assertSame(['PKG-A'], $first->fresh()->channel_package_ids);
        $this->assertSame(['PKG-B'], $second->fresh()->channel_package_ids);

        $this->assertSame(2, ChannelOperationAttempt::query()
            ->where('operation', 'request_awb')
            ->where('status', ChannelOperationAttempt::STATUS_SUCCEEDED)
            ->count());

        Queue::assertNotPushed(RequestChannelAwbJob::class);
        Queue::assertPushed(PrepareShopeeShippingLabelJob::class, 2);
    }

    private function createWaitingOrder(
        BulkShippingLabelBatch $batch,
        string $orderSn,
        string $packageNumber,
    ): SalesOrder {
        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-MASS-AWB',
            'channel_order_no' => $orderSn,
            'channel_status' => 'READY_TO_SHIP',
            'status' => 'reserved',
            'tracking_number' => null,
            'channel_package_ids' => [$packageNumber],
            'shipping_label_status' => null,
        ]);

        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_WAITING_AWB,
        ]);

        return $order;
    }
}
