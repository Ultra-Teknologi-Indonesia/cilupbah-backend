<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use App\Models\UserHistory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /users (create) & PUT /users/{id} (update) — modul Auth.
 * Fokus: berhasil, mencatat history, dan TIDAK PERNAH error 500.
 */
class UserStoreApiTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'StrongP@ss1',
            'password_confirmation' => 'StrongP@ss1',
            'roles' => ['picker'],
        ], $overrides);
    }

    public function test_owner_can_create_user(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'John Doe')
            ->assertJsonPath('data.email', 'john@example.com');

        $created = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('picker'));
        $this->assertTrue(Hash::check('StrongP@ss1', $created->password));
    }

    public function test_create_user_records_created_history(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload())
            ->assertStatus(201);

        $created = User::where('email', 'john@example.com')->first();

        $this->assertDatabaseHas('user_histories', [
            'target_user_id' => $created->id,
            'actor_id' => $this->owner->id,
            'action' => 'created',
        ]);
    }

    public function test_update_user_records_updated_history(): void
    {
        $target = User::factory()->create();
        $target->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}", [
                'name' => 'John Updated',
                'email' => 'john.updated@example.com',
                'roles' => ['checker'],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_histories', [
            'target_user_id' => $target->id,
            'actor_id' => $this->owner->id,
            'action' => 'updated',
        ]);
    }

    public function test_history_endpoint_shows_created_message(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload())
            ->assertStatus(201);

        $created = User::where('email', 'john@example.com')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/users/{$created->id}/histories")
            ->assertStatus(200)
            ->assertJsonPath('data.0.action', 'created')
            ->assertJsonPath('data.0.actor.id', $this->owner->id);
    }

    // ── Guard no-500: input invalid → 422, akses tak sah → 403 ──

    public function test_missing_fields_returns_422_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'roles']);
    }

    public function test_weak_password_returns_422(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload([
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_duplicate_email_returns_422(): void
    {
        User::factory()->create(['email' => 'john@example.com']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_cannot_assign_owner_role_returns_422(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload(['roles' => ['owner']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0']);
    }

    public function test_nonexistent_role_returns_422(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload(['roles' => ['ghost-role']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0']);
    }

    public function test_invalid_warehouse_id_returns_422_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload(['warehouse_id' => 'not-a-real-id']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_update_with_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/users/not-a-uuid', [
                'name' => 'X',
                'email' => 'x@example.com',
                'roles' => ['picker'],
            ])
            ->assertStatus(404);
    }

    public function test_update_invalid_warehouse_id_returns_422_not_500(): void
    {
        $target = User::factory()->create();
        $target->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}", [
                'name' => 'X',
                'email' => 'x@example.com',
                'roles' => ['picker'],
                'warehouse_id' => 'not-a-real-id',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_histories_with_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/users/not-a-uuid/histories')
            ->assertStatus(404);
    }

    public function test_unauthorized_user_is_forbidden(): void
    {
        $plain = User::factory()->create();
        $plain->assignRole('picker'); // tidak punya create-user

        $this->actingAs($plain, 'sanctum')
            ->postJson('/api/v1/users', $this->validPayload())
            ->assertStatus(403);
    }
}
