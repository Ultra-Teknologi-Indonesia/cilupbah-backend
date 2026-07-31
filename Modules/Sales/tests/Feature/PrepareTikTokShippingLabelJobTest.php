<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'source'           => 'tiktok',
            'channel_shop_id'  => 'SHOP-1',
            'channel_order_no' => 'TT-ORDER-1',
            'tracking_number'  => 'AWB123',
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
}
