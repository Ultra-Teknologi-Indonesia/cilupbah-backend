<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\ShippingLabelPrefetch;
use Modules\Sales\Services\ShippingLabelPrefetchService;
use Tests\TestCase;

class ShippingLabelPrefetchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shipping-label-prefetch.enabled', true);
    }

    private function regularOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'source' => 'shopee',
            'channel_shop_id' => 'shop-prefetch',
            'channel_order_no' => 'SHP-PREFETCH-001',
            'is_paid' => true,
            'is_canceled' => false,
            'is_shadow' => false,
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
            'channel_instant' => false,
            'tracking_number' => null,
            'shipping_label_status' => null,
        ], $overrides));
    }

    public function test_regular_order_creates_durable_intent_and_low_priority_job(): void
    {
        Queue::fake();
        $order = $this->regularOrder();

        $scheduled = app(ShippingLabelPrefetchService::class)->schedule($order);

        $this->assertTrue($scheduled);
        $this->assertDatabaseHas('shipping_label_prefetches', [
            'order_id' => $order->id,
            'status' => ShippingLabelPrefetch::STATUS_QUEUED,
        ]);
        Queue::assertPushed(RequestChannelAwbJob::class, fn (RequestChannelAwbJob $job): bool => $job->orderId === $order->id
            && $job->prefetch
            && ! $job->requestReadyToShip
            && $job->verificationOnly
            && $job->queue === config('shipping-label-prefetch.queue')
        );
    }

    public function test_instant_or_unknown_order_is_never_a_prefetch_candidate(): void
    {
        Queue::fake();

        $instant = $this->regularOrder([
            'channel_order_no' => 'SHP-PREFETCH-INSTANT',
            'channel_instant' => true,
        ]);
        $unknown = $this->regularOrder([
            'channel_order_no' => 'SHP-PREFETCH-UNKNOWN',
            'channel_instant' => null,
        ]);
        $service = app(ShippingLabelPrefetchService::class);

        $this->assertFalse($service->schedule($instant));
        $this->assertFalse($service->schedule($unknown));
        Queue::assertNotPushed(RequestChannelAwbJob::class);
        $this->assertDatabaseMissing('shipping_label_prefetches', ['order_id' => $instant->id]);
        $this->assertDatabaseMissing('shipping_label_prefetches', ['order_id' => $unknown->id]);
    }

    public function test_unpaid_or_return_order_is_never_a_prefetch_candidate(): void
    {
        Queue::fake();
        $service = app(ShippingLabelPrefetchService::class);

        $unpaid = $this->regularOrder([
            'channel_order_no' => 'SHP-PREFETCH-UNPAID',
            'channel_status' => 'UNPAID',
        ]);
        $returned = $this->regularOrder([
            'channel_order_no' => 'SHP-PREFETCH-RETURNED',
            'channel_status' => 'RETURN_REQUESTED',
        ]);

        $this->assertFalse($service->schedule($unpaid));
        $this->assertFalse($service->schedule($returned));
        Queue::assertNotPushed(RequestChannelAwbJob::class);
    }

    public function test_processed_order_without_local_awb_can_prefetch_label_read_only(): void
    {
        Queue::fake();
        $order = $this->regularOrder([
            'channel_order_no' => 'SHP-PREFETCH-PROCESSED',
            'channel_status' => 'PROCESSED',
        ]);

        $this->assertTrue(app(ShippingLabelPrefetchService::class)->schedule($order));
        Queue::assertPushed(RequestChannelAwbJob::class, fn (RequestChannelAwbJob $job): bool => $job->orderId === $order->id);
    }

    public function test_disabled_feature_does_not_schedule_any_order(): void
    {
        Queue::fake();
        config()->set('shipping-label-prefetch.enabled', false);
        $order = $this->regularOrder();

        $this->assertFalse(app(ShippingLabelPrefetchService::class)->schedule($order));
        Queue::assertNotPushed(RequestChannelAwbJob::class);
    }

    public function test_prefetch_job_uses_the_low_priority_queue(): void
    {
        $job = new RequestChannelAwbJob('order-id', 0, true, true);

        $this->assertSame(config('shipping-label-prefetch.queue'), $job->queue);
        $this->assertSame(config('shipping-label-prefetch.connection'), $job->connection);
    }
}
