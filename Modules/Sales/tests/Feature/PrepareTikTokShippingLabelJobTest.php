<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Jobs\PrepareTikTokShippingLabelJob;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class PrepareTikTokShippingLabelJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-1',
            'channel_order_no' => 'TT-ORDER-1',
            'tracking_number' => 'AWB123',
        ], $overrides));
    }

    public function test_marks_ready_and_caches_document_when_package_doc_available(): void
    {
        $order = $this->makeOrder();

        $this->mock(TikTokOrderService::class, function ($m) {
            $m->shouldReceive('packageIdsForOrder')->once()->with('SHOP-1', 'TT-ORDER-1')->andReturn(['PKG1']);
            $m->shouldReceive('getShippingDocument')->once()->with('SHOP-1', 'PKG1', 'SHIPPING_LABEL', 'A6')
                ->andReturn(['code' => 0, 'data' => ['doc_url' => 'https://tts/label.pdf']]);
        });

        (new PrepareTikTokShippingLabelJob($order->id))->handle(app(TikTokOrderService::class));

        $order->refresh();
        $this->assertSame('ready', $order->shipping_label_status);
        $this->assertSame('PDF', $order->shipping_label_doc_type);
        $this->assertNotNull($order->shipping_label_prepared_at);
        $this->assertSame('https://tts/label.pdf', $order->shipping_label_raw_data['documents'][0]['doc_url']);
    }

    public function test_skips_when_tracking_number_empty(): void
    {
        $order = $this->makeOrder(['tracking_number' => null]);

        $this->mock(TikTokOrderService::class, function ($m) {
            $m->shouldNotReceive('packageIdsForOrder');
            $m->shouldNotReceive('getShippingDocument');
        });

        (new PrepareTikTokShippingLabelJob($order->id))->handle(app(TikTokOrderService::class));

        $this->assertNull($order->refresh()->shipping_label_status);
    }

    public function test_ignores_non_tiktok_orders(): void
    {
        $order = $this->makeOrder(['source' => 'shopee']);

        $this->mock(TikTokOrderService::class, function ($m) {
            $m->shouldNotReceive('packageIdsForOrder');
        });

        (new PrepareTikTokShippingLabelJob($order->id))->handle(app(TikTokOrderService::class));

        $this->assertNull($order->refresh()->shipping_label_status);
    }

    public function test_recovers_a_stale_preparing_order(): void
    {
        $order = $this->makeOrder(['shipping_label_status' => 'preparing']);

        $this->mock(TikTokOrderService::class, function ($m) {
            $m->shouldReceive('packageIdsForOrder')->once()->with('SHOP-1', 'TT-ORDER-1')->andReturn(['PKG1']);
            $m->shouldReceive('getShippingDocument')->once()->with('SHOP-1', 'PKG1', 'SHIPPING_LABEL', 'A6')
                ->andReturn(['code' => 0, 'data' => ['doc_url' => 'https://tts/label.pdf']]);
        });

        (new PrepareTikTokShippingLabelJob($order->id))->handle(app(TikTokOrderService::class));

        $this->assertSame('ready', $order->refresh()->shipping_label_status);
    }

    public function test_does_not_mark_ready_when_any_package_document_is_still_missing(): void
    {
        Queue::fake();
        $order = $this->makeOrder(['channel_package_ids' => ['PKG1', 'PKG2']]);

        $this->mock(TikTokOrderService::class, function ($m) {
            $m->shouldNotReceive('packageIdsForOrder');
            $m->shouldReceive('getShippingDocument')->with('SHOP-1', 'PKG1', 'SHIPPING_LABEL', 'A6')
                ->andReturn(['code' => 0, 'data' => ['doc_url' => 'https://tts/label-1.pdf']]);
            $m->shouldReceive('getShippingDocument')->with('SHOP-1', 'PKG2', 'SHIPPING_LABEL', 'A6')
                ->andThrow(new \RuntimeException('label masih diproses'));
        });

        (new PrepareTikTokShippingLabelJob($order->id))->handle(app(TikTokOrderService::class));

        $this->assertSame('not_ready', $order->refresh()->shipping_label_status);
        Queue::assertPushed(
            PrepareTikTokShippingLabelJob::class,
            fn (PrepareTikTokShippingLabelJob $job): bool => $job->orderId === $order->id,
        );
    }
}
