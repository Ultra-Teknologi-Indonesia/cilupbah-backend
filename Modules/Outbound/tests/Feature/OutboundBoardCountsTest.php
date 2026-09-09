<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboundBoardCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_web_user_gets_all_board_counts_in_one_response(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $response = $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/outbound/orders/counts');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [
                    'picking' => ['belum', 'diproses', 'selesai'],
                    'packing' => ['belum', 'diproses', 'selesai'],
                    'shipping' => ['siap-kirim', 'jadwal', 'batal'],
                ],
            ])
            ->assertJsonPath('data.picking.belum', 0)
            ->assertJsonPath('data.picking.diproses', 0)
            ->assertJsonPath('data.picking.selesai', 0)
            ->assertJsonPath('data.packing.belum', 0)
            ->assertJsonPath('data.packing.diproses', 0)
            ->assertJsonPath('data.packing.selesai', 0)
            ->assertJsonPath('data.shipping.siap-kirim', 0)
            ->assertJsonPath('data.shipping.jadwal', 0)
            ->assertJsonPath('data.shipping.batal', 0);
    }

    public function test_user_without_view_permission_cannot_read_board_counts(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/outbound/orders/counts')
            ->assertForbidden();
    }

    public function test_board_badges_are_returned_by_api_from_the_visible_dataset(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'LOC-BC-' . substr($locationId, 0, 6),
            'location_name' => 'Gudang Board Counts',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-BC-' . substr($orderId, 0, 6),
            'customer_name' => 'Board Counts Buyer',
            'location_id' => $locationId,
            'status' => 'cancelled',
            'is_canceled' => true,
            'handed_to_warehouse_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('packlists')->insert([
            'id' => Str::uuid()->toString(),
            'packlist_no' => 'PACK-BC-' . Str::random(6),
            'location_id' => $locationId,
            'order_id' => $orderId,
            'status' => 'COMPLETED',
            'completed_at' => now(),
            'created_by' => 'system:test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipments')->insert([
            'id' => Str::uuid()->toString(),
            'shipment_no' => 'SHP-BC-' . Str::random(6),
            'location_id' => $locationId,
            'shipment_type' => 'REGULAR',
            'shipment_date' => now()->toDateString(),
            'status' => 'SCHEDULED',
            'created_by' => 'system:test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/outbound/orders/counts')
            ->assertOk()
            ->assertJsonPath('data.shipping.batal', 1)
            ->assertJsonPath('data.shipping.jadwal', 1);
    }
}
