<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderAuditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_audit_requires_view_permission(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/operations/order-audit?reference=SO-AUDIT-001')
            ->assertForbidden();
    }

    public function test_authorized_viewer_can_run_read_only_order_audit(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/operations/order-audit?reference=SO-AUDIT-001')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [
                    'reference',
                    'found_in_wms',
                    'orders',
                    'webhooks',
                    'actions' => ['can_include', 'can_delete'],
                ],
            ])
            ->assertJsonPath('data.reference', 'SO-AUDIT-001');
    }

    public function test_replay_requires_edit_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson('/api/v1/operations/order-audit/replay', [
                'reference' => 'SO-AUDIT-001',
                'confirmation' => 'REPLAY-ORDER',
            ])
            ->assertForbidden();
    }
}
