<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WarehouseSettingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    public function test_index_returns_default_setting(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/systemsetting/warehouse-layout')
            ->assertStatus(200)
            ->assertJsonPath('data.use_warehouse_layout', true);
    }

    public function test_can_toggle_warehouse_layout_setting(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/warehouse-layout', ['use_warehouse_layout' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.use_warehouse_layout', false);

        $this->assertDatabaseHas('warehouse_settings', ['use_warehouse_layout' => false]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/systemsetting/warehouse-layout')
            ->assertJsonPath('data.use_warehouse_layout', false);
    }

    public function test_toggle_requires_boolean(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/systemsetting/warehouse-layout', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['use_warehouse_layout']);
    }
}
