<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    public function test_filter_by_unknown_role_returns_empty_200_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/users?filter[role]=ghost-role')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_filter_by_role_returns_matching_users(): void
    {
        $picker = User::factory()->create();
        $picker->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/users?filter[role]=picker')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $picker->id);
    }

    public function test_user_list_includes_assigned_locations(): void
    {
        $location = Location::factory()->create([
            'location_name' => 'Gudang Kecil',
        ]);
        $target = User::factory()->create([
            'email' => 'picker.location@example.com',
        ]);
        $target->assignRole('picker');
        $target->syncLocations([$location->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/users?search=picker.location@example.com')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.locations.0.location_id', $location->id)
            ->assertJsonPath('data.0.locations.0.location_name', 'Gudang Kecil');
    }

    public function test_systemsetting_users_lookup_accessible_without_view_user_permission(): void
    {
        $plain = User::factory()->create();

        $this->actingAs($plain, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(403);

        $this->actingAs($plain, 'sanctum')
            ->getJson('/api/v1/systemsetting/users?pageSize=10&page=1')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['user_id', 'email', 'last_login', 'is_owner']],
                'totalCount',
            ]);
    }

    public function test_systemsetting_users_lookup_filters_by_q(): void
    {
        $owner = User::factory()->create(['email' => 'owner-unik@example.com']);
        $owner->assignRole('owner');
        User::factory()->create(['email' => 'lainnya@example.com']);

        $res = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/systemsetting/users?q=owner-unik')
            ->assertStatus(200)
            ->assertJsonPath('totalCount', 1);

        $this->assertSame('owner-unik@example.com', $res->json('data.0.email'));
        $this->assertTrue($res->json('data.0.is_owner'));
    }

    public function test_show_returns_user_detail(): void
    {
        $target = User::factory()->create();
        $target->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/users/{$target->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.email', $target->email)
            ->assertJsonPath('data.roles.0', 'picker');
    }

    public function test_show_with_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/users/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_show_with_unknown_uuid_returns_404(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/users/' . Str::uuid())
            ->assertStatus(404);
    }

    public function test_update_without_nik_and_warehouse_preserves_existing_values(): void
    {
        $target = User::factory()->create(['nik' => '3201019999']);
        $target->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}", [
                'name' => 'Updated Name',
                'email' => $target->email,
                'roles' => ['picker'],
            ])
            ->assertStatus(200);

        $this->assertSame('3201019999', $target->fresh()->nik);
    }

    public function test_update_with_explicit_null_nik_clears_it(): void
    {
        $target = User::factory()->create(['nik' => '3201019999']);
        $target->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}", [
                'name' => 'Updated Name',
                'email' => $target->email,
                'roles' => ['picker'],
                'nik' => null,
            ])
            ->assertStatus(200);

        $this->assertNull($target->fresh()->nik);
    }

    public function test_owner_can_delete_user(): void
    {
        $target = User::factory()->create();
        $target->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/users/{$target->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_delete_preserves_history_and_allows_a_clean_account_with_same_email(): void
    {
        $email = 'recyclable.user@example.com';
        $target = User::factory()->create([
            'name' => 'User Lama',
            'email' => $email,
        ]);
        $target->assignRole('picker');
        $targetId = $target->id;

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/users/{$targetId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $targetId]);
        $this->assertDatabaseMissing('model_has_roles', [
            'model_id' => $targetId,
            'model_type' => User::class,
        ]);
        $this->assertDatabaseHas('user_histories', [
            'target_user_id' => null,
            'target_user_id_snapshot' => $targetId,
            'target_user_name' => 'User Lama',
            'target_user_email' => $email,
            'action' => 'deleted',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/users/{$targetId}/histories")
            ->assertStatus(200)
            ->assertJsonPath('data.0.action', 'deleted')
            ->assertJsonPath('data.0.target_user_name', 'User Lama');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'User Baru',
                'email' => $email,
                'password' => 'StrongP@ss1',
                'password_confirmation' => 'StrongP@ss1',
                'roles' => ['picker'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', $email);

        $newUser = User::where('email', $email)->firstOrFail();
        $this->assertNotSame($targetId, $newUser->id);
        $this->assertTrue($newUser->hasRole('picker'));
    }

    public function test_cannot_delete_own_account_returns_422(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/users/{$this->owner->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $this->owner->id]);
    }

    public function test_cannot_delete_owner_account_returns_403(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo('delete-user');

        $otherOwner = User::factory()->create();
        $otherOwner->assignRole('owner');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$otherOwner->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $otherOwner->id]);
    }

    public function test_delete_with_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/users/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_unauthorized_user_cannot_delete(): void
    {
        $plain = User::factory()->create();
        $plain->assignRole('picker');

        $target = User::factory()->create();
        $target->assignRole('checker');

        $this->actingAs($plain, 'sanctum')
            ->deleteJson("/api/v1/users/{$target->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_export_returns_xlsx_download(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->get('/api/v1/users/export')
            ->assertStatus(200)
            ->assertHeader('content-disposition');
    }
}
