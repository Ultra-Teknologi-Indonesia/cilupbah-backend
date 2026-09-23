<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Jobs\RequestTikTokMassAwbJob;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use Tests\TestCase;

final class RequestTikTokMassAwbJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_id_deduplicates_same_shop_and_orders_across_batches(): void
    {
        $first = new RequestTikTokMassAwbJob('BATCH-A', 'SHOP-TIKTOK-MASS', ['A', 'B']);
        $second = new RequestTikTokMassAwbJob('BATCH-B', 'SHOP-TIKTOK-MASS', ['B', 'A']);

        $this->assertSame($first->uniqueId(), $second->uniqueId());
    }

    public function test_preflights_orders_then_ships_their_packages_in_one_request(): void
    {
        Queue::fake();

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-TIKTOK-MASS',
            'shop_name' => 'TikTok Mass AWB',
            'shop_cipher' => 'cipher',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
            'fulfillment_push_enabled' => true,
        ]);

        $first = $this->createOrder('TIKTOK-MASS-A', 'PKG-A');
        $second = $this->createOrder('TIKTOK-MASS-B', 'PKG-B');

        $tiktok = Mockery::mock(TikTokOrderService::class);
        $tiktok->shouldReceive('getOrderFulfillmentSnapshot')
            ->twice()
            ->andReturn(
                $this->snapshot('PKG-A'),
                $this->snapshot('PKG-B'),
            );
        $tiktok->shouldReceive('requestTrackingNumbersMass')
            ->once()
            ->with('SHOP-TIKTOK-MASS', ['PKG-A', 'PKG-B'])
            ->andReturn([
                ['package_id' => 'PKG-A', 'shipped' => true],
                ['package_id' => 'PKG-B', 'shipped' => true],
            ]);

        (new RequestTikTokMassAwbJob(
            'BATCH-MASS',
            'SHOP-TIKTOK-MASS',
            [(string) $first->id, (string) $second->id],
        ))->handle(
            $tiktok,
            app(ChannelShopRepository::class),
            app(BulkShippingLabelService::class),
            app(ShippingLabelPreparationDispatcher::class),
        );

        $this->assertSame(2, ChannelOperationAttempt::query()
            ->where('operation', 'request_awb')
            ->where('status', ChannelOperationAttempt::STATUS_ACCEPTED)
            ->count());
        Queue::assertPushed(
            RequestChannelAwbJob::class,
            fn (RequestChannelAwbJob $job): bool => $job->verificationOnly && $job->trackingAttempt === 1,
        );
        Queue::assertPushed(RequestChannelAwbJob::class, 2);
    }

    private function createOrder(string $channelOrderNo, string $packageId): SalesOrder
    {
        return SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-TIKTOK-MASS',
            'channel_order_no' => $channelOrderNo,
            'channel_package_ids' => [$packageId],
            'channel_status' => 'AWAITING_SHIPMENT',
            'tracking_number' => null,
            'status' => 'reserved',
        ]);
    }

    private function snapshot(string $packageId): array
    {
        return [
            'order_found' => true,
            'status' => 'AWAITING_SHIPMENT',
            'tracking_number' => null,
            'packages' => [[
                'id' => $packageId,
                'status' => 'AWAITING_SHIPMENT',
                'tracking_number' => null,
            ]],
        ];
    }
}
