<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Exceptions\ScanRejectedException;
use Modules\Outbound\Services\ShipmentService;
use Tests\TestCase;

class ShipmentScanGuardTest extends TestCase
{
    use RefreshDatabase;

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-SG-' . substr($id, 0, 6),
            'location_name' => 'Gudang SG',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
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
            'shipment_no' => 'SHP-SG-' . substr($id, 0, 6),
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

    private function seedPackedOrder(string $locationId, string $provider, ?string $shippingType = null): array
    {
        $orderId = Str::uuid()->toString();
        $no = 'SO-SG-' . substr($orderId, 0, 6);
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => $no,
            'customer_name' => 'Buyer',
            'source' => null,
            'location_id' => $locationId,
            'status' => 'packed',
            'is_canceled' => false,
            'shipping_provider' => $provider,
            'shipping_type' => $shippingType,
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

    public function test_rejects_instant_order_into_regular_manifest(): void
    {
        $loc = $this->seedLocation();

        $shipmentId = $this->seedShipment($loc, 'SPX', 'REGULAR');
        [, $no] = $this->seedPackedOrder($loc, 'SPX Instant');

        $this->expectException(ScanRejectedException::class);
        $this->expectExceptionMessageMatches('/INSTAN/i');

        app(ShipmentService::class)->scanAndAddOrder($shipmentId, $no);
    }
}
