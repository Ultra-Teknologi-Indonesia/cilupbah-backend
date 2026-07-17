<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class SalesOrderActivityEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/sales/00000000-0000-0000-0000-000000000000/activities')
            ->assertStatus(401);
    }

    public function test_endpoint_forbidden_without_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $order = SalesOrder::factory()->create();

        $this->getJson("/api/v1/sales/{$order->id}/activities")
            ->assertStatus(403);
    }

    public function test_endpoint_returns_activities_desc_ordered(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');
        Sanctum::actingAs($user);

        $order = SalesOrder::factory()->create();

        app(SalesOrderService::class)->logStatusHistory(
            $order,
            OrderActivityAction::CREATED,
            ['prev_values' => [], 'new_values' => ['wms_status' => 'CREATED']],
        );
        app(SalesOrderService::class)->logStatusHistory(
            $order,
            OrderActivityAction::PAID,
            ['prev_values' => ['is_paid' => false], 'new_values' => ['is_paid' => true]],
        );

        $response = $this->getJson("/api/v1/sales/{$order->id}/activities")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    ['id', 'action_date', 'email', 'entity_type', 'entity_no',
                     'action', 'action_id', 'action_label',
                     'prev_values', 'new_values'],
                ],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            ]);

        $labels = collect($response->json('data'))->pluck('action_label')->all();
        // PAID lebih baru dari CREATED → muncul lebih dulu (desc)
        $this->assertSame(['PAID', 'CREATED'], $labels);
    }

    public function test_endpoint_returns_404_for_missing_order(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/sales/019f0000-0000-7000-8000-000000000000/activities')
            ->assertStatus(404);
    }

    public function test_channel_status_mutator_normalizes_shopee_raw_code(): void
    {
        $order = SalesOrder::factory()->create([
            'source'         => 'shopee',
            'channel_status' => 'TO_RETURN',
        ]);

        $this->assertSame('RETURN_REQUESTED', $order->fresh()->channel_status);
    }

    public function test_channel_status_mutator_preserves_canonical(): void
    {
        $order = SalesOrder::factory()->create([
            'source'         => 'shopee',
            'channel_status' => 'SHIPPED',
        ]);

        $this->assertSame('SHIPPED', $order->fresh()->channel_status);
    }

    public function test_channel_status_unknown_channel_falls_back_to_unknown(): void
    {
        $order = SalesOrder::factory()->create([
            'source'         => 'bukalapak',
            'channel_status' => 'WEIRD_CODE_XYZ',
        ]);

        $this->assertSame('UNKNOWN', $order->fresh()->channel_status);
    }

    public function test_logstatushistory_writes_row_with_enum_cast(): void
    {
        $user = User::factory()->create();
        $order = SalesOrder::factory()->create();

        app(SalesOrderService::class)->logStatusHistory(
            $order,
            OrderActivityAction::FINISH_PICK,
            ['new_values' => ['status' => 'picked']],
            $user,
        );

        $row = SalesOrderStatusHistory::query()
            ->where('salesorder_id', $order->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(OrderActivityAction::FINISH_PICK, $row->action);
        $this->assertSame('600', $row->action_id);
        $this->assertSame('ORDER', $row->entity_type->value);
        $this->assertSame($user->email, $row->actor_email);
    }
}
