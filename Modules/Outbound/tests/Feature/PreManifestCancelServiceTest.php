<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Services\PreManifestCancelService;
use Modules\Outbound\Services\ShipmentService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class PreManifestCancelServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-PM-' . substr($id, 0, 6),
            'location_name' => 'Gudang PM',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedOrder(string $status, string $locationId, array $overrides = []): string
    {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert(array_merge([
            'id' => $orderId,
            'salesorder_no' => 'SO-PM-' . substr($orderId, 0, 6),
            'customer_name' => 'Buyer',
            'source' => null,
            'location_id' => $locationId,
            'status' => $status,
            'is_canceled' => $status === 'cancelled',
            'handed_to_warehouse_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $orderId;
    }

    public function test_count_returns_only_undismissed_cancel_post_pack_orders(): void
    {
        $loc = $this->seedLocation();
        $this->seedOrder('cancelled', $loc);
        $this->seedOrder('cancelled', $loc);
        $this->seedOrder('cancelled', $loc, ['cancel_dismissed_at' => now(), 'cancel_dismissed_by' => 'u1']);
        $this->seedOrder('cancelled', $loc, ['handed_to_warehouse_at' => null]);
        $this->seedOrder('packed', $loc);

        $service = app(PreManifestCancelService::class);

        $this->assertSame(2, $service->count());
    }

    public function test_dismiss_sets_timestamp_and_actor_and_is_idempotent(): void
    {
        $loc = $this->seedLocation();
        $orderId = $this->seedOrder('cancelled', $loc);

        $service = app(PreManifestCancelService::class);
        $service->dismiss($orderId, 'user-a@company.test');

        $first = SalesOrder::find($orderId);
        $this->assertNotNull($first->cancel_dismissed_at);
        $this->assertSame('user-a@company.test', $first->cancel_dismissed_by);

        $service->dismiss($orderId, 'user-b@company.test');
        $second = SalesOrder::find($orderId);
        $this->assertSame(
            $first->cancel_dismissed_at->toDateTimeString(),
            $second->cancel_dismissed_at->toDateTimeString(),
        );
        $this->assertSame('user-a@company.test', $second->cancel_dismissed_by);
    }

    public function test_dismiss_rejects_non_cancelled_order(): void
    {
        $loc = $this->seedLocation();
        $orderId = $this->seedOrder('packed', $loc);

        $this->expectException(\Exception::class);
        app(PreManifestCancelService::class)->dismiss($orderId, 'system');
    }

    public function test_dismiss_rejects_cancel_before_handoff(): void
    {
        $loc = $this->seedLocation();
        $orderId = $this->seedOrder('cancelled', $loc, ['handed_to_warehouse_at' => null]);

        $this->expectException(\Exception::class);
        app(PreManifestCancelService::class)->dismiss($orderId, 'system');
    }

    public function test_undismiss_clears_dismiss_state(): void
    {
        $loc = $this->seedLocation();
        $orderId = $this->seedOrder('cancelled', $loc);

        $service = app(PreManifestCancelService::class);
        $service->dismiss($orderId, 'user-a');
        $service->undismiss($orderId);

        $order = SalesOrder::find($orderId);
        $this->assertNull($order->cancel_dismissed_at);
        $this->assertNull($order->cancel_dismissed_by);
    }

    public function test_add_orders_rejects_cancelled_order(): void
    {
        $loc = $this->seedLocation();
        $packedId = $this->seedOrder('packed', $loc);
        $cancelledId = $this->seedOrder('cancelled', $loc);

        $shipmentId = Str::uuid()->toString();
        DB::table('shipments')->insert([
            'id' => $shipmentId,
            'shipment_no' => 'SHP-PM-' . substr($shipmentId, 0, 6),
            'location_id' => $loc,
            'shipment_type' => 'REGULAR',
            'shipment_date' => now()->toDateString(),
            'status' => 'SCHEDULED',
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/dibatalkan|packed/i');

        app(ShipmentService::class)->addOrders($shipmentId, [$packedId, $cancelledId]);
    }
}
