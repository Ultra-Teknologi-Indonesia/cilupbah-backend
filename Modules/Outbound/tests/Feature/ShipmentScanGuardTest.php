<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Exceptions\ScanRejectedException;
use Modules\Outbound\Services\ShipmentService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ShipmentScanGuardTest extends TestCase
{
    use RefreshDatabase;

    private function seedLocation(): string
    {
        $officialId = Location::getOfficialSmallWarehouseId();
        if ($officialId) {
            return (string) $officialId;
        }

        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => Location::SYSTEM_KECIL_CODE,
            'location_name' => 'Gudang Kecil',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_small_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedShipment(string $locationId, string $courierName, string $shipmentType): string
    {
        $id = Str::uuid()->toString();
        DB::table('shipments')->insert([
            'id' => $id,
            'shipment_no' => 'SHP-SG-'.substr($id, 0, 6),
            'location_id' => $locationId,
            'courier_name' => $courierName,
            'shipment_type' => $shipmentType,
            'shipment_date' => now()->toDateString(),
            'status' => 'SCHEDULED',
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedPackedOrder(
        string $locationId,
        string $provider,
        ?string $shippingType = null,
        bool $isCanceled = false,
        ?string $cancelRequestedAt = null,
        ?bool $channelInstant = null,
        ?string $source = null,
    ): array {
        $orderId = Str::uuid()->toString();
        $no = 'SO-SG-'.substr($orderId, 0, 6);
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => $no,
            'customer_name' => 'Buyer',
            'source' => $source,
            'is_manual' => $source === null,
            'location_id' => $locationId,
            'status' => 'packed',
            'is_canceled' => $isCanceled,
            'cancel_requested_at' => $cancelRequestedAt,
            'shipping_provider' => $provider,
            'shipping_type' => $shippingType,
            'channel_instant' => $channelInstant,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$orderId, $no];
    }

    public function test_rejects_scan_when_canonical_courier_differs(): void
    {
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'J&T', 'REGULAR');

        [, $no] = $this->seedPackedOrder($loc, 'Drop-off: LEX ID, Delivery: JNE Cashless');

        $this->expectException(ScanRejectedException::class);
        $this->expectExceptionMessageMatches('/Kurir tidak sesuai/i');

        app(ShipmentService::class)->scanAndAddOrder($shipmentId, $no);
    }

    public function test_allows_scan_for_alias_variant_of_same_courier(): void
    {
        Bus::fake();
        $loc = $this->seedLocation();

        $shipmentId = $this->seedShipment($loc, 'Shopee Xpress', 'INSTANT');
        [$orderId, $no] = $this->seedPackedOrder($loc, 'SPX Instant');

        app(ShipmentService::class)->scanAndAddOrder($shipmentId, $no);

        $this->assertDatabaseHas('shipment_orders', [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);
    }

    public function test_allows_scan_for_gtl_and_goto_logistics(): void
    {
        Bus::fake();
        $loc = $this->seedLocation();

        $shipmentId = $this->seedShipment($loc, 'GTL', 'REGULAR');
        [$orderId, $no] = $this->seedPackedOrder($loc, 'GoTo Logistics GTL');

        app(ShipmentService::class)->scanAndAddOrder($shipmentId, $no);

        $this->assertDatabaseHas('shipment_orders', [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);
    }

    public function test_manifest_orders_are_sorted_by_most_recent_scan_first(): void
    {
        Bus::fake();
        $locationId = $this->seedLocation();
        $shipmentId = $this->seedShipment($locationId, 'J&T', 'REGULAR');
        [$firstOrderId, $firstOrderNo] = $this->seedPackedOrder($locationId, 'J&T');
        [$secondOrderId, $secondOrderNo] = $this->seedPackedOrder($locationId, 'J&T');

        $service = app(ShipmentService::class);
        $first = $service->scanAndAddOrder($shipmentId, $firstOrderNo);
        DB::table('shipment_orders')
            ->where('id', $first->shipmentOrder->id)
            ->update(['created_at' => now()->subMinute()]);
        $service->scanAndAddOrder($shipmentId, $secondOrderNo);

        $orders = $service->getOrdersPaginated($shipmentId, 20);

        $this->assertSame($secondOrderId, $orders->first()->order_id);
        $this->assertSame($firstOrderId, $orders->last()->order_id);
    }

    public function test_rejects_instant_order_into_regular_manifest(): void
    {
        $loc = $this->seedLocation();

        $shipmentId = $this->seedShipment($loc, 'SPX', 'REGULAR');
        [, $no] = $this->seedPackedOrder($loc, 'SPX Instant');

        $this->expectException(ScanRejectedException::class);
        $this->expectExceptionMessageMatches('/INSTAN/i');

        app(ShipmentService::class)->scanAndAddOrder($shipmentId, $no);
    }

    public function test_rejects_scan_when_order_is_canceled(): void
    {
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'JNE', 'REGULAR');
        [, $no] = $this->seedPackedOrder($loc, 'JNE', isCanceled: true);

        try {
            app(ShipmentService::class)->scanAndAddOrder($shipmentId, $no);
            $this->fail('Expected ScanRejectedException for canceled order.');
        } catch (ScanRejectedException $e) {
            $this->assertSame('order_canceled', $e->reason);
        }
    }

    public function test_rejects_scan_when_order_cancel_requested(): void
    {
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'JNE', 'REGULAR');
        [, $no] = $this->seedPackedOrder($loc, 'JNE', cancelRequestedAt: now()->toDateTimeString());

        try {
            app(ShipmentService::class)->scanAndAddOrder($shipmentId, $no);
            $this->fail('Expected ScanRejectedException for cancel-requested order.');
        } catch (ScanRejectedException $e) {
            $this->assertSame('order_cancel_requested', $e->reason);
        }
    }

    public function test_bulk_add_allows_a_packed_internal_order_to_regular_shipment(): void
    {
        Bus::fake();
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'JNE', 'REGULAR');
        [$orderId] = $this->seedPackedOrder($loc, 'JNE');

        app(ShipmentService::class)->addOrders($shipmentId, [$orderId], true);

        $this->assertDatabaseHas('shipment_orders', [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);
    }

    public function test_bulk_add_allows_a_packed_marketplace_instant_order_to_instant_shipment(): void
    {
        Bus::fake();
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'GoSend', 'INSTANT');
        [$orderId] = $this->seedPackedOrder($loc, 'GoSend Instant', channelInstant: true, source: 'shopee');

        app(ShipmentService::class)->addOrders($shipmentId, [$orderId]);

        $this->assertDatabaseHas('shipment_orders', [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);
    }

    public function test_bulk_add_allows_a_packed_marketplace_regular_order_to_regular_shipment(): void
    {
        Bus::fake();
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'SPX Hemat', 'REGULAR');
        [$orderId] = $this->seedPackedOrder($loc, 'SPX Hemat', source: 'shopee');

        app(ShipmentService::class)->addOrders($shipmentId, [$orderId]);

        $this->assertDatabaseHas('shipment_orders', [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);
    }

    public function test_bulk_add_rejects_a_marketplace_regular_order_until_packed(): void
    {
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'SPX Hemat', 'REGULAR');
        [$orderId, $orderNo] = $this->seedPackedOrder($loc, 'SPX Hemat', source: 'shopee');
        DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'reserved']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(
            "Order berikut dibatalkan atau bukan status 'packed' dan tidak bisa dimanifestkan: {$orderNo}"
        );

        app(ShipmentService::class)->addOrders($shipmentId, [$orderId]);
    }

    public function test_bulk_add_rejects_a_regular_order_to_an_instant_shipment(): void
    {
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'GoSend', 'INSTANT');
        [$orderId, $orderNo] = $this->seedPackedOrder($loc, 'JNE', source: 'shopee');

        $this->expectException(OutboundValidationException::class);
        $this->expectExceptionMessage("Pesanan {$orderNo} tidak sesuai dengan pengiriman instant/same-day.");

        app(ShipmentService::class)->addOrders($shipmentId, [$orderId]);
    }

    public function test_bulk_add_rejects_a_channel_order_for_internal_only_shipment(): void
    {
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'JNE', 'REGULAR');
        [$orderId] = $this->seedPackedOrder($loc, 'JNE', source: 'shopee');

        $this->expectException(OutboundValidationException::class);
        $this->expectExceptionMessage('hanya untuk pesanan internal/manual');

        app(ShipmentService::class)->addOrders($shipmentId, [$orderId], true);
    }

    public function test_bulk_add_rejects_an_order_with_channel_identity_even_when_source_is_empty(): void
    {
        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'JNE', 'REGULAR');
        [$orderId] = $this->seedPackedOrder($loc, 'JNE');
        DB::table('sales_orders')->where('id', $orderId)->update([
            'channel_order_no' => 'CHANNEL-SG-001',
        ]);

        $this->expectException(OutboundValidationException::class);
        $this->expectExceptionMessage('hanya untuk pesanan internal/manual');

        app(ShipmentService::class)->addOrders($shipmentId, [$orderId], true);
    }

    public function test_internal_only_api_returns_validation_error_instead_of_server_error_for_channel_order(): void
    {
        $operator = $this->createPrivilegedUser();
        $this->actingAs($operator, 'sanctum');

        $loc = $this->seedLocation();
        $shipmentId = $this->seedShipment($loc, 'JNE', 'REGULAR');
        [$orderId] = $this->seedPackedOrder($loc, 'JNE', source: 'tiktok');

        $this->postJson("/api/v1/outbound/shipments/{$shipmentId}/add-orders", [
            'order_ids' => [$orderId],
            'internal_only' => true,
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Buat Pengiriman ini hanya untuk pesanan internal/manual. Pesanan channel tidak dapat dimasukkan: '.DB::table('sales_orders')->where('id', $orderId)->value('salesorder_no')]);

        $this->assertDatabaseMissing('shipment_orders', [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);
    }
}
