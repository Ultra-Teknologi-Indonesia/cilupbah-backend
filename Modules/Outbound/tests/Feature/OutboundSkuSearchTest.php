<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class OutboundSkuSearchTest extends TestCase
{
    use RefreshDatabase;

    private function createOrderWithItem(string $orderNo, string $sku, string $status = 'reserved'): SalesOrder
    {
        $order = SalesOrder::create([
            'salesorder_no' => $orderNo,
            'source' => 'manual',
            'status' => $status,
            'customer_name' => 'Test Customer',
            'handed_to_warehouse_at' => now(),
            'transaction_date' => now(),
        ]);

        $order->items()->create([
            'sku' => $sku,
            'name' => 'Test Product ' . $sku,
            'quantity' => 2,
            'price' => 10000,
        ]);

        return $order;
    }

    public function test_stage_orders_can_be_searched_by_product_sku(): void
    {
        $orderA = $this->createOrderWithItem('SO-TEST-001', 'SKU-ALPHA-123');
        $orderB = $this->createOrderWithItem('SO-TEST-002', 'SKU-BETA-456');

        request()->merge(['search' => 'ALPHA-123']);

        $service = app(OutboundFulfillmentService::class);
        $result = $service->getOrdersByStage('ready-to-process', 10);

        $orderNumbers = collect($result->items())->pluck('salesorder_no')->all();
        $this->assertContains('SO-TEST-001', $orderNumbers);
        $this->assertNotContains('SO-TEST-002', $orderNumbers);
    }
}
