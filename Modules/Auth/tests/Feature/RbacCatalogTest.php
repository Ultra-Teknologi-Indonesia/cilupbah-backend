<?php

namespace Modules\Auth\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Support\PermissionCatalog;
use Tests\TestCase;

class RbacCatalogTest extends TestCase
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

    public function test_seeder_creates_every_catalog_permission(): void
    {
        $expected = PermissionCatalog::allPermissionNames();

        $this->assertNotEmpty($expected);
        $this->assertSame(
            count($expected),
            Permission::whereIn('name', $expected)->count(),
            'Semua permission di katalog harus ada di database.'
        );
    }

    public function test_catalog_endpoint_returns_grouped_matrix(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/permissions/catalog')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    ['key', 'label', 'resources' => [
                        ['key', 'label', 'actions' => [['action', 'label', 'permission']], 'extras'],
                    ]],
                ],
            ]);

        $groups = $response->json('data');
        $this->assertCount(count(config('rbac.groups')), $groups);

        // Pastikan salah satu permission bernama benar mengikuti konvensi {action}-{resource}.
        $found = collect($groups)
            ->flatMap(fn ($g) => $g['resources'])
            ->flatMap(fn ($r) => array_column($r['actions'], 'permission'))
            ->contains('view-produk');
        $this->assertTrue($found, 'view-produk harus muncul di katalog.');
    }

    public function test_default_grants_are_scoped_per_role(): void
    {
        $admin = Role::where('name', 'admin')->first();
        $this->assertSame(count(PermissionCatalog::allPermissionNames()), $admin->permissions()->count());

        $picker = Role::where('name', 'picker')->first();
        $this->assertTrue($picker->hasPermissionTo('view-picking'));
        $this->assertFalse($picker->hasPermissionTo('view-user'));
    }

    public function test_owner_bypasses_gated_route_without_explicit_permission(): void
    {
        // owner tidak diberi grant eksplisit, tapi bypass via Gate::before.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(200);
    }

    public function test_non_owner_without_permission_is_forbidden_then_allowed(): void
    {
        $user = User::factory()->create();
        $user->assignRole('picker'); // picker tidak punya view-user

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(403);

        $user->givePermissionTo('view-user');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(200);
    }

    public function test_per_user_permission_override_endpoint_syncs_direct_permissions(): void
    {
        $target = User::factory()->create();
        $target->assignRole('picker');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/users/{$target->id}/permissions", [
                'permissions' => ['view-user', 'export-user'],
            ])
            ->assertStatus(200);

        $target->refresh();
        $this->assertTrue($target->hasDirectPermission('view-user'));
        $this->assertTrue($target->hasDirectPermission('export-user'));

        // Profil pengguna memantulkan gabungan izin role + langsung.
        $this->actingAs($target, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertStatus(200)
            ->assertJsonFragment(['view-user']);
    }

    public function test_owner_direct_permissions_cannot_be_overridden(): void
    {
        $anotherOwner = User::factory()->create();
        $anotherOwner->assignRole('owner');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/v1/users/{$anotherOwner->id}/permissions", [
                'permissions' => ['view-user'],
            ])
            ->assertStatus(403);
    }
}
