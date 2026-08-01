<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\User;
use App\Support\WarehouseAccess;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class WarehouseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Location $pusat;
    protected Location $kecil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');

        $this->pusat = Location::firstOrCreate(
            ['location_code' => Location::SYSTEM_PUSAT_CODE],
            ['location_name' => 'Gudang Pusat', 'is_warehouse' => true, 'is_active' => true],
        );
        $this->kecil = Location::firstOrCreate(
            ['location_code' => Location::SYSTEM_KECIL_CODE],
            ['location_name' => 'Gudang Kecil', 'is_warehouse' => true, 'is_active' => true],
        );
    }

    private function member(array $locationIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole('picker');
        if ($locationIds) {
            $user->syncLocations($locationIds);
        }

        return $user->fresh();
    }

    public function test_unassigned_user_is_unrestricted(): void
    {
        $this->assertNull($this->member()->allowedLocationIds());
    }

    public function test_owner_is_unrestricted_even_when_assigned(): void
    {
        $this->owner->syncLocations([$this->kecil->id]);

        $this->assertNull($this->owner->fresh()->allowedLocationIds());
    }

    public function test_single_assignment_limits_to_that_warehouse(): void
    {
        $user = $this->member([$this->kecil->id]);

        $this->assertEqualsCanonicalizing([$this->kecil->id], $user->allowedLocationIds());
    }

    public function test_multi_assignment_limits_to_those_warehouses(): void
    {
        $user = $this->member([$this->kecil->id, $this->pusat->id]);

        $this->assertEqualsCanonicalizing(
            [$this->kecil->id, $this->pusat->id],
            $user->allowedLocationIds()
        );
    }

    public function test_assert_blocks_disallowed_and_allows_permitted(): void
    {
        Auth::setUser($this->member([$this->kecil->id]));

        WarehouseAccess::assert($this->kecil->id);
        WarehouseAccess::assert(null);

        $this->expectException(AuthorizationException::class);
        WarehouseAccess::assert($this->pusat->id);
    }

    public function test_constrain_intersects_with_allowed(): void
    {
        Auth::setUser($this->member([$this->kecil->id]));

        $this->assertSame([$this->kecil->id], WarehouseAccess::constrain(null));
        $this->assertSame([$this->kecil->id], WarehouseAccess::constrain([$this->kecil->id, $this->pusat->id]));
        $this->assertSame([], WarehouseAccess::constrain([$this->pusat->id]));
    }

    public function test_constrain_is_passthrough_for_unrestricted_user(): void
    {
        Auth::setUser($this->member());

        $this->assertNull(WarehouseAccess::constrain(null));
        $this->assertSame([$this->pusat->id], WarehouseAccess::constrain([$this->pusat->id]));
    }

    public function test_create_user_syncs_assigned_warehouses(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Gudang Kecil Staff',
            'email' => 'kecil@example.com',
            'password' => 'StrongP@ss1',
            'password_confirmation' => 'StrongP@ss1',
            'roles' => ['picker'],
            'warehouse_id' => $this->kecil->id,
            'location_ids' => [$this->kecil->id],
        ]);

        $response->assertStatus(201);

        $created = User::where('email', 'kecil@example.com')->first();
        $this->assertEqualsCanonicalizing([$this->kecil->id], $created->allowedLocationIds());
    }

    public function test_default_warehouse_must_be_within_assigned_set(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'StrongP@ss1',
            'password_confirmation' => 'StrongP@ss1',
            'roles' => ['picker'],
            'warehouse_id' => $this->pusat->id,      
            'location_ids' => [$this->kecil->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_locations_endpoint_scoped_for_restricted_user(): void
    {
        \App\Models\Permission::findOrCreate('view-manajemen-rak', 'web');
        $user = $this->member([$this->kecil->id]);
        $user->givePermissionTo('view-manajemen-rak');

        $response = $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/v1/locations?per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->kecil->id, $ids);
        $this->assertNotContains($this->pusat->id, $ids);
    }

    public function test_locations_endpoint_returns_all_for_owner(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/locations?per_page=100');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->kecil->id, $ids);
        $this->assertContains($this->pusat->id, $ids);
    }

    public function test_update_can_clear_assignment_back_to_unrestricted(): void
    {
        $user = $this->member([$this->kecil->id]);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => ['picker'],
            'location_ids' => [],
        ])->assertStatus(200);

        $this->assertNull($user->fresh()->allowedLocationIds());
    }
}
