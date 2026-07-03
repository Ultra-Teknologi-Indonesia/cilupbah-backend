<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Tests\TestCase;

class OutboundFulfillmentCancelVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(string $status, string $locationId): string
    {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-CV-' . substr($orderId, 0, 6),
            'customer_name' => 'Buyer',
            'source' => null,
            'location_id' => $locationId,
            'status' => $status,
            'is_canceled' => $status === 'cancelled',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function seedLocation(): string
    {
        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'LOC-CV-' . substr($locationId, 0, 6),
            'location_name' => 'Gudang CV',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $locationId;
    }

    private function seedCompletedPacklist(string $orderId, string $locationId): void
    {
        DB::table('packlists')->insert([
            'id' => Str::uuid()->toString(),
            'packlist_no' => 'PACK-' . Str::random(6),
            'location_id' => $locationId,
            'order_id' => $orderId,
            'status' => 'COMPLETED',
            'completed_at' => now(),
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedScheduledShipmentAssignment(string $orderId, string $locationId): void
    {
        $shipmentId = Str::uuid()->toString();
        DB::table('shipments')->insert([
            'id' => $shipmentId,
            'shipment_no' => 'SHP-CV-' . substr($shipmentId, 0, 6),
            'location_id' => $locationId,
            'shipment_type' => 'REGULAR',
            'shipment_date' => now()->toDateString(),
            'status' => 'SCHEDULED',
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('shipment_orders')->insert([
            'id' => Str::uuid()->toString(),
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_cancelled_order_with_completed_packlist_and_no_shipment_appears_in_finish_pack(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder('cancelled', $locationId);
        $this->seedCompletedPacklist($orderId, $locationId);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage('finish-pack');

        $this->assertTrue(collect($page->items())->pluck('id')->contains($orderId));
    }

    public function test_cancelled_order_without_packlist_history_does_not_appear_in_finish_pack(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder('cancelled', $locationId);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage('finish-pack');

        $this->assertFalse(collect($page->items())->pluck('id')->contains($orderId));
    }

    public function test_cancelled_order_in_scheduled_shipment_appears_in_ready_to_ship(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder('cancelled', $locationId);
        $this->seedCompletedPacklist($orderId, $locationId);
        $this->seedScheduledShipmentAssignment($orderId, $locationId);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage('ready-to-ship');

        $this->assertTrue(collect($page->items())->pluck('id')->contains($orderId));
    }

    public function test_cancelled_order_not_in_any_shipment_does_not_appear_in_ready_to_ship(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder('cancelled', $locationId);
        $this->seedCompletedPacklist($orderId, $locationId);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage('ready-to-ship');

        $this->assertFalse(collect($page->items())->pluck('id')->contains($orderId));
    }

    public function test_active_packed_order_still_appears_in_finish_pack(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder('packed', $locationId);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage('finish-pack');

        $this->assertTrue(collect($page->items())->pluck('id')->contains($orderId));
    }
}
