<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class PrepareShopeeShippingLabelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_when_shopee_label_ready_it_updates_status(): void
    {
        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP123',
            'channel_order_no' => 'SN123',
            'tracking_number' => 'AWB123',
            'shipping_label_status' => null,
        ]);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('checkAllowSelfDesignAwb')->once()->andReturn(false);
        $shopee->shouldReceive('resolveSupportedDocType')->once()->andReturn('THERMAL_AIR_WAYBILL');
        $shopee->shouldReceive('createShippingDocument')->once()->andReturn(['status' => 'OK']);
        $shopee->shouldReceive('getShippingDocumentResult')->once()->andReturn([
            'response' => [
                'result_list' => [
                    ['status' => 'READY'],
                ],
            ],
        ]);

        $job = new PrepareShopeeShippingLabelJob($order->id, 0);
        $job->handle($shopee);

        $order->refresh();
        $this->assertEquals('ready', $order->shipping_label_status);
        $this->assertEquals('THERMAL_AIR_WAYBILL', $order->shipping_label_doc_type);
        $this->assertNotNull($order->shipping_label_prepared_at);
    }

    public function test_when_shopee_label_not_ready_it_dispatches_delayed_retry_non_blocking(): void
    {
        Queue::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP123',
            'channel_order_no' => 'SN123',
            'tracking_number' => 'AWB123',
            'shipping_label_status' => null,
        ]);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('checkAllowSelfDesignAwb')->once()->andReturn(false);
        $shopee->shouldReceive('resolveSupportedDocType')->once()->andReturn('THERMAL_AIR_WAYBILL');
        $shopee->shouldReceive('createShippingDocument')->once()->andReturn(['status' => 'OK']);
        $shopee->shouldReceive('getShippingDocumentResult')->once()->andReturn([
            'response' => [
                'result_list' => [
                    ['status' => 'PROCESSING'],
                ],
            ],
        ]);

        $job = new PrepareShopeeShippingLabelJob($order->id, 0);
        $job->handle($shopee);

        $order->refresh();
        $this->assertEquals('preparing', $order->shipping_label_status);

        Queue::assertPushed(PrepareShopeeShippingLabelJob::class, function ($pushedJob) use ($order) {
            return $pushedJob->orderId === $order->id && $pushedJob->attempt === 1;
        });
    }
}
