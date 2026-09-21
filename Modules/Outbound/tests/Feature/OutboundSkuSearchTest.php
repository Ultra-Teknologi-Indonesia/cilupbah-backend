<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'name' => 'Test Product '.$sku,
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

    public function test_stage_orders_search_combined_with_filter(): void
    {
        $orderA = $this->createOrderWithItem('SO-TEST-SPX', 'LSM-001');
        $orderA->update(['shipping_provider' => 'SPX Hemat']);

        $orderB = $this->createOrderWithItem('SO-TEST-JNT', 'LSM-002');
        $orderB->update(['shipping_provider' => 'J&T Express']);

        $orderC = $this->createOrderWithItem('SO-TEST-SPX-OTHER', 'XYZ-999');
        $orderC->update(['shipping_provider' => 'SPX Hemat']);

        request()->merge([
            'search' => 'lsm-',
            'filter' => ['shipping_provider' => 'SPX Hemat'],
        ]);

        $service = app(OutboundFulfillmentService::class);
        $result = $service->getOrdersByStage('ready-to-process', 10);

        $orderNumbers = collect($result->items())->pluck('salesorder_no')->all();
        $this->assertContains('SO-TEST-SPX', $orderNumbers);
        $this->assertNotContains('SO-TEST-JNT', $orderNumbers);
        $this->assertNotContains('SO-TEST-SPX-OTHER', $orderNumbers);
    }

    public function test_stage_orders_search_by_item_description(): void
    {
        $orderA = $this->createOrderWithItem('SO-DESC-001', 'SKU-UNKNOWN');
        $orderA->items()->first()->update(['description' => 'Tali Gantungan LSM-BLACK Original']);

        $orderB = $this->createOrderWithItem('SO-DESC-002', 'SKU-OTHER');
        $orderB->items()->first()->update(['description' => 'Casing HP Silicone Red']);

        request()->merge(['search' => 'LSM-BLACK']);

        $service = app(OutboundFulfillmentService::class);
        $result = $service->getOrdersByStage('ready-to-process', 10);

        $orderNumbers = collect($result->items())->pluck('salesorder_no')->all();
        $this->assertContains('SO-DESC-001', $orderNumbers);
        $this->assertNotContains('SO-DESC-002', $orderNumbers);
    }

    public function test_stage_orders_search_by_current_picklist_number(): void
    {
        $locationId = (string) Str::uuid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'LOC-PICK-'.substr($locationId, 0, 6),
            'location_name' => 'Gudang Test Picklist',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderA = $this->createOrderWithItem('SO-PICK-001', 'SKU-PICK-001', 'picked');
        $orderB = $this->createOrderWithItem('SO-PICK-002', 'SKU-PICK-002', 'picked');
        $orderA->update(['location_id' => $locationId]);
        $orderB->update(['location_id' => $locationId]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Test Picklist Category',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Test Picklist Product',
            'order_type' => 'REGULER',
            'condition' => 'NEW',
            'is_cod_allowed' => false,
            'danger_level' => 0,
            'is_draft' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variantId = (string) Str::uuid();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => 'VARIANT-PICK-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderA->items()->update(['item_id' => $variantId]);
        $orderB->items()->update(['item_id' => $variantId]);

        $this->createCompletedPicklist($orderA, 'PICK-00000123');
        $this->createCompletedPicklist($orderB, 'PICK-00000456');

        request()->merge(['search' => '00000123']);

        $result = app(OutboundFulfillmentService::class)
            ->getOrdersByStage('finish-pick', 10);

        $orderNumbers = collect($result->items())->pluck('salesorder_no')->all();

        $this->assertContains('SO-PICK-001', $orderNumbers);
        $this->assertNotContains('SO-PICK-002', $orderNumbers);
    }

    private function createCompletedPicklist(SalesOrder $order, string $picklistNo): void
    {
        $picklist = Picklist::create([
            'picklist_no' => $picklistNo,
            'location_id' => $order->location_id,
            'status' => Picklist::STATUS_COMPLETED,
            'completed_at' => now(),
            'created_by' => 'system:test',
        ]);

        $item = $order->items()->firstOrFail();

        $picklist->items()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'item_id' => $item->item_id,
            'sku' => $item->sku,
            'qty_ordered' => $item->qty_in_base,
            'qty_picked' => $item->qty_in_base,
        ]);
    }
}
