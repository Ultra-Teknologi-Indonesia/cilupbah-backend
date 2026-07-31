<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sales\Http\Resources\SalesOrderResource;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class SalesOrderResourceShippingLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_exposes_shipping_label_block(): void
    {
        $order = SalesOrder::factory()->create([
            'source'                     => 'tiktok',
            'shipping_label_status'      => 'ready',
            'shipping_label_doc_type'    => 'PDF',
            'shipping_label_prepared_at' => now(),
        ]);

        $arr = (new SalesOrderResource($order))->toArray(request());

        $this->assertArrayHasKey('shipping_label', $arr);
        $this->assertSame('ready', $arr['shipping_label']['status']);
        $this->assertSame('PDF', $arr['shipping_label']['doc_type']);
        $this->assertNotNull($arr['shipping_label']['prepared_at']);
    }

    public function test_shipping_label_status_null_when_unprepared(): void
    {
        $order = SalesOrder::factory()->create(['source' => 'shopee']);

        $arr = (new SalesOrderResource($order))->toArray(request());

        $this->assertArrayHasKey('shipping_label', $arr);
        $this->assertNull($arr['shipping_label']['status']);
    }
}
