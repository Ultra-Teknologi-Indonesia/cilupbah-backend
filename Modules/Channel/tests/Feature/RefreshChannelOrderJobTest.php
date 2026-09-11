<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WooCommerceOrderService;
use Tests\TestCase;

final class RefreshChannelOrderJobTest extends TestCase
{
    public function test_it_refreshes_the_order_using_the_latest_channel_detail(): void
    {
        $shopee = \Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('pullOrderById')
            ->once()
            ->with('SHOP-1', 'ORDER-1')
            ->andReturn(1);

        $orders = new ChannelOrderRefreshService(
            $shopee,
            \Mockery::mock(TikTokOrderService::class),
            \Mockery::mock(LazadaOrderService::class),
            \Mockery::mock(WooCommerceOrderService::class),
        );

        $job = new RefreshChannelOrderJob('shopee', 'SHOP-1', 'ORDER-1');
        $job->handle($orders);

        self::assertSame('shopee:SHOP-1:ORDER-1', $job->uniqueId());
        self::assertSame(config('queue.names.shopee_orders'), $job->queue);
    }
}
