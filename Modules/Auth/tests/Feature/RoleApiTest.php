<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(RbacPermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    public function test_index_returns_paginated_roles(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/roles')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'users_count']], 'meta']);
    }

    public function test_show_returns_role_detail_with_permissions(): void
    {
        $role = Role::where('name', 'picker')->first();
        $role->givePermissionTo('view-user');

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/roles/{$role->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'picker');

        $this->assertContains('view-user', $response->json('data.permissions'));
    }

    public function test_show_with_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/roles/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_show_with_unknown_uuid_returns_404(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/roles/'.Str::uuid())
            ->assertStatus(404);
    }

    public function test_create_role_normalizes_name_to_lowercase(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/roles', ['name' => 'Security Team'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'security team');

        $this->assertDatabaseHas('roles', ['name' => 'security team']);
    }

    public function test_create_duplicate_name_case_insensitive_returns_422_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/roles', ['name' => 'ADMIN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_role(): void
    {
        $role = Role::where('name', 'picker')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}", ['name' => 'Picker Senior'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'picker senior');
    }

    public function test_update_with_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/roles/not-a-uuid', ['name' => 'x'])
            ->assertStatus(404);
    }

    public function test_update_to_existing_name_case_insensitive_returns_422(): void
    {
        $role = Role::where('name', 'picker')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}", ['name' => 'ADMIN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_owner_role_returns_403(): void
    {
        $role = Role::where('name', 'owner')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}", ['name' => 'super'])
            ->assertStatus(403);
    }

    public function test_delete_unused_role(): void
    {
        $role = Role::create(['name' => 'temporary', 'guard_name' => 'web']);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_delete_role_in_use_returns_422_not_orphaned_users(): void
    {
        $role = Role::where('name', 'picker')->first();
        $user = User::factory()->create();
        $user->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_delete_owner_role_returns_403(): void
    {
        $role = Role::where('name', 'owner')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(403);
    }

    public function test_delete_with_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/roles/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_sync_permissions(): void
    {
        $role = Role::where('name', 'picker')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => ['view-user', 'view-dashboard']])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.permissions');

        $this->assertTrue($role->fresh()->hasPermissionTo('view-user'));
    }

    public function test_sync_permissions_empty_array_revokes_all(): void
    {
        $role = Role::where('name', 'picker')->first();
        $role->givePermissionTo('view-user');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => []])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.permissions');
    }

    public function test_sync_permissions_on_owner_role_returns_403(): void
    {
        $role = Role::where('name', 'owner')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => ['view-user']])
            ->assertStatus(403);
    }

    public function test_sync_permissions_with_unknown_permission_returns_422_not_500(): void
    {
        $role = Role::where('name', 'picker')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => ['ghost-permission']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_sync_permissions_without_key_returns_422(): void
    {
        $role = Role::where('name', 'picker')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/roles/{$role->id}/permissions", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions']);
    }

    public function test_unauthorized_user_cannot_manage_roles(): void
    {
        $plain = User::factory()->create();
        $plain->assignRole('picker');

        $this->actingAs($plain, 'sanctum')
            ->postJson('/api/v1/roles', ['name' => 'hacker'])
            ->assertStatus(403);
    }
}
