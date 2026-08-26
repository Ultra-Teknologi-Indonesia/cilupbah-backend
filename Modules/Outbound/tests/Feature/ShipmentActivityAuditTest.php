<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Jobs\ProcessShipmentPickupJob;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Services\ShipmentService;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Tests\TestCase;

class ShipmentActivityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanning_order_records_actor_server_time_and_shipment_in_activity_api(): void
    {
        Bus::fake();
        $operator = $this->createPrivilegedUser(['name' => 'Operator Manifest']);
        $this->actingAs($operator, 'sanctum');

        $locationId = $this->seedLocation();
        $shipmentId = $this->seedShipment($locationId);
        [$orderId, $orderNo] = $this->seedPackedOrder($locationId);

        $before = now();
        app(ShipmentService::class)->scanAndAddOrder($shipmentId, $orderNo);
        $after = now();

        $history = SalesOrderStatusHistory::query()
            ->where('salesorder_id', $orderId)
            ->where('action', OrderActivityAction::ADDED_TO_SHIPMENT)
            ->sole();

        $this->assertSame($operator->id, $history->actor_id);
        $this->assertSame($operator->email, $history->actor_email);
        $this->assertSame('Operator Manifest', $history->actor_name);
        $this->assertSame($shipmentId, $history->entity_id);
        $this->assertSame('SHP-AUDIT-'.substr($shipmentId, 0, 6), $history->metadata['entity_no']);
        $this->assertLessThanOrEqual(5, $history->created_at->diffInSeconds($before, true));
        $this->assertLessThanOrEqual(5, $history->created_at->diffInSeconds($after, true));

        $this->getJson("/api/v1/sales/{$orderId}/activities?per_page=50")
            ->assertOk()
            ->assertJsonPath('data.0.action_label', OrderActivityAction::ADDED_TO_SHIPMENT->value)
            ->assertJsonPath('data.0.email', $operator->email)
            ->assertJsonPath('data.0.actor_name', 'Operator Manifest')
            ->assertJsonPath('data.0.entity_no', $history->metadata['entity_no']);
    }

    public function test_repeated_manifest_scan_is_idempotent_and_returns_the_existing_shipment_order(): void
    {
        Bus::fake();
        $operator = $this->createPrivilegedUser(['name' => 'Operator Manifest']);
        $this->actingAs($operator, 'sanctum');

        $locationId = $this->seedLocation();
        $shipmentId = $this->seedShipment($locationId);
        [$orderId, $orderNo] = $this->seedPackedOrder($locationId);

        $service = app(ShipmentService::class);
        $first = $service->scanAndAddOrder($shipmentId, $orderNo);
        $second = $service->scanAndAddOrder($shipmentId, $orderNo);

        $this->assertFalse($first->alreadyAdded);
        $this->assertTrue($second->alreadyAdded);
        $this->assertSame($first->shipmentOrder->id, $second->shipmentOrder->id);
        $this->assertDatabaseCount('shipment_orders', 1);
        $this->assertSame(
            1,
            SalesOrderStatusHistory::query()
                ->where('salesorder_id', $orderId)
                ->where('action', OrderActivityAction::ADDED_TO_SHIPMENT)
                ->count(),
        );
        Bus::assertDispatchedTimes(ProcessShipmentPickupJob::class, 1);
    }

    public function test_scan_api_returns_the_scanned_tracking_number_for_immediate_display(): void
    {
        Bus::fake();
        $operator = $this->createPrivilegedUser();
        $this->actingAs($operator, 'sanctum');

        $locationId = $this->seedLocation();
        $shipmentId = $this->seedShipment($locationId);
        [, $orderNo] = $this->seedPackedOrder($locationId, 'J&T Express');

        $this->postJson("/api/v1/outbound/shipments/{$shipmentId}/scan-order", [
            'barcode' => $orderNo,
        ])
            ->assertOk()
            ->assertJsonPath('data.scan_result.status', 'added')
            ->assertJsonPath('data.scan_result.barcode', $orderNo)
            ->assertJsonPath('data.scan_result.shipment_order.order.salesorder_no', $orderNo);
    }

    public function test_bulk_add_is_idempotent_and_does_not_duplicate_audit_rows(): void
    {
        Bus::fake();
        $operator = $this->createPrivilegedUser();
        $this->actingAs($operator, 'sanctum');

        $locationId = $this->seedLocation();
        $shipmentId = $this->seedShipment($locationId);
        [$firstOrderId] = $this->seedPackedOrder($locationId, 'J&T Express');
        [$secondOrderId] = $this->seedPackedOrder($locationId, 'J&T Express');
        $orderIds = [$firstOrderId, $secondOrderId];

        $service = app(ShipmentService::class);
        $service->addOrders($shipmentId, $orderIds);
        $service->addOrders($shipmentId, $orderIds);

        $this->assertDatabaseCount('shipment_orders', 2);
        $this->assertSame(
            2,
            SalesOrderStatusHistory::query()
                ->whereIn('salesorder_id', $orderIds)
                ->where('action', OrderActivityAction::ADDED_TO_SHIPMENT)
                ->count(),
        );
        Bus::assertDispatchedTimes(ProcessShipmentPickupJob::class, 1);
    }

    public function test_handover_records_one_audit_row_per_manifest_order(): void
    {
        Bus::fake();
        $operator = $this->createPrivilegedUser(['name' => 'Operator Handover']);
        $this->actingAs($operator, 'sanctum');

        $locationId = $this->seedLocation();
        $shipmentId = $this->seedShipment($locationId);
        [$firstOrderId] = $this->seedPackedOrder($locationId, 'J&T Express');
        [$secondOrderId] = $this->seedPackedOrder($locationId, 'J&T Express');

        $service = app(ShipmentService::class);
        $service->addOrders($shipmentId, [$firstOrderId, $secondOrderId]);
        $service->handOver($shipmentId);

        try {
            $service->handOver($shipmentId);
            $this->fail('A handed-over shipment must not be handed over twice.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('SCHEDULED', $exception->getMessage());
        }

        $this->assertDatabaseHas('shipments', [
            'id' => $shipmentId,
            'status' => Shipment::STATUS_HANDED_OVER,
        ]);
        $this->assertSame(
            2,
            SalesOrderStatusHistory::query()
                ->whereIn('salesorder_id', [$firstOrderId, $secondOrderId])
                ->where('action', OrderActivityAction::SHIPMENT_HANDED_OVER)
                ->where('actor_id', $operator->id)
                ->count(),
        );
        $this->assertNotNull(
            SalesOrderStatusHistory::query()
                ->where('salesorder_id', $firstOrderId)
                ->where('action', OrderActivityAction::SHIPMENT_HANDED_OVER)
                ->value('created_at'),
        );
    }

    public function test_removing_order_records_audit_row_after_relation_is_deleted(): void
    {
        Bus::fake();
        $operator = $this->createPrivilegedUser();
        $this->actingAs($operator, 'sanctum');

        $locationId = $this->seedLocation();
        $shipmentId = $this->seedShipment($locationId);
        [$orderId] = $this->seedPackedOrder($locationId, 'J&T Express');

        $service = app(ShipmentService::class);
        $service->addOrders($shipmentId, [$orderId]);
        $service->removeOrders($shipmentId, [$orderId]);

        $this->assertDatabaseMissing('shipment_orders', [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);
        $this->assertDatabaseHas('sales_order_status_histories', [
            'salesorder_id' => $orderId,
            'action' => OrderActivityAction::REMOVED_FROM_SHIPMENT->value,
            'actor_id' => $operator->id,
        ]);
    }

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-AUDIT-'.substr($id, 0, 6),
            'location_name' => 'Gudang Audit',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedShipment(string $locationId): string
    {
        $id = Str::uuid()->toString();
        DB::table('shipments')->insert([
            'id' => $id,
            'shipment_no' => 'SHP-AUDIT-'.substr($id, 0, 6),
            'location_id' => $locationId,
            'courier_name' => 'J&T',
            'courier_code' => 'jnt',
            'shipment_type' => 'REGULAR',
            'shipment_date' => now()->toDateString(),
            'status' => Shipment::STATUS_SCHEDULED,
            'created_by' => 'system:test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedPackedOrder(string $locationId, string $provider = 'J&T'): array
    {
        $orderId = Str::uuid()->toString();
        $orderNo = 'SO-AUDIT-'.substr($orderId, 0, 6);
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => $orderNo,
            'customer_name' => 'Buyer Audit',
            'location_id' => $locationId,
            'status' => 'packed',
            'is_canceled' => false,
            'shipping_provider' => $provider,
            'shipping_type' => 'REGULAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $orderNo];
    }
}
