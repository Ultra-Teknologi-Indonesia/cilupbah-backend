<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\PrepareTikTokShippingLabelJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use Tests\TestCase;

class ShippingLabelPreparationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_preparing_orders_are_requeued_idempotently_for_all_channels(): void
    {
        Queue::fake();

        $jobs = [
            'shopee' => PrepareShopeeShippingLabelJob::class,
            'tiktok' => PrepareTikTokShippingLabelJob::class,
            'lazada' => PrepareLazadaShippingLabelJob::class,
        ];

        foreach ($jobs as $source => $jobClass) {
            $order = SalesOrder::factory()->create([
                'source' => $source,
                'tracking_number' => "AWB-{$source}",
                'channel_shop_id' => "SHOP-{$source}",
                'channel_order_no' => "ORDER-{$source}",
                'shipping_label_status' => 'preparing',
            ]);

            $dispatcher = app(ShippingLabelPreparationDispatcher::class);

            $this->assertTrue($dispatcher->dispatch($order));
            $this->assertFalse($dispatcher->dispatch($order->fresh()));

            Queue::assertPushed(
                $jobClass,
                fn ($job): bool => $job->orderId === $order->id,
            );

            Cache::forget("shipping-label:dispatch-marker:{$order->id}");
        }
    }

    public function test_does_not_dispatch_without_tracking_or_for_terminal_label_state(): void
    {
        Queue::fake();

        $dispatcher = app(ShippingLabelPreparationDispatcher::class);

        $withoutTracking = SalesOrder::factory()->create([
            'source' => 'shopee',
            'tracking_number' => null,
            'shipping_label_status' => 'preparing',
        ]);

        $ready = SalesOrder::factory()->create([
            'source' => 'tiktok',
            'tracking_number' => 'AWB-READY',
            'shipping_label_status' => 'ready',
        ]);

        $this->assertFalse($dispatcher->dispatch($withoutTracking));
        $this->assertFalse($dispatcher->dispatch($ready));
        Queue::assertNotPushed(PrepareShopeeShippingLabelJob::class);
        Queue::assertNotPushed(PrepareTikTokShippingLabelJob::class);
        Queue::assertNotPushed(PrepareLazadaShippingLabelJob::class);
    }
}
