<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
