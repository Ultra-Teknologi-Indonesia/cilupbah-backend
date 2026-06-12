<?php

namespace Modules\Product\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Product\Database\Seeders\ProductPermissionSeeder;
use Tests\TestCase;

/**
 * Verifikasi gating berbasis Spatie Permission untuk endpoint merge.
 * (Sengaja TIDAK memanggil withoutMiddleware supaya middleware permission aktif.)
 */
class ProductMergePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(ProductPermissionSeeder::class);
    }

    public function test_all_endpoints_require_sanctum_login(): void
    {
        // Tanpa autentikasi → 401 di SEMUA endpoint (auth:sanctum jalan sebelum permission)
        $this->getJson('/api/v1/products/merge/catalog')->assertStatus(401);
        $this->getJson('/api/v1/products/merge/suggestions')->assertStatus(401);
        $this->getJson('/api/v1/products/merge/applied')->assertStatus(401);
        $this->postJson('/api/v1/products/merge/auto')->assertStatus(401);
        $this->postJson('/api/v1/products/merge/apply', [])->assertStatus(401);
        $this->postJson('/api/v1/products/merge/bulk', [])->assertStatus(401);
        $this->postJson('/api/v1/products/merge/bulk-unmerge', [])->assertStatus(401);
        $this->postJson('/api/v1/products/merge/hide', [])->assertStatus(401);
        $this->postJson('/api/v1/products/merge/unhide', [])->assertStatus(401);
        $this->deleteJson('/api/v1/products/merge/master', [])->assertStatus(401);
        $this->deleteJson('/api/v1/products/merge/019ea2af-ad1d-733e-afb9-05816d10590e')->assertStatus(401);
    }

    public function test_authenticated_without_permission_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create()); // tanpa role/permission

        $this->getJson('/api/v1/products/merge/catalog')->assertStatus(403);
        $this->postJson('/api/v1/products/merge/auto')->assertStatus(403);
    }

    public function test_permission_grants_access_per_action(): void
    {
        // Role kustom hanya dengan permission view → boleh baca, tak boleh mutasi
        $role = Role::create(['name' => 'merge-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('view-product-merge');

        $user = User::factory()->create();
        $user->assignRole('merge-viewer');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/products/merge/catalog')->assertStatus(200);
        $this->postJson('/api/v1/products/merge/auto')->assertStatus(403);

        // Tambah permission auto-merge → endpoint auto jadi boleh
        $role->givePermissionTo('auto-merge-product');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson('/api/v1/products/merge/auto')->assertStatus(200);
    }

    public function test_admin_role_has_all_merge_permissions_from_seeder(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/products/merge/catalog')->assertStatus(200);
        $this->postJson('/api/v1/products/merge/auto')->assertStatus(200);
        $this->postJson('/api/v1/products/merge/hide', ['master_names' => ['X']])->assertStatus(200);
    }

    public function test_owner_bypasses_via_gate_before(): void
    {
        // Owner sengaja TIDAK diberi permission merge apa pun secara manual;
        // harus tetap lolos karena Gate::before.
        Permission::query()->whereIn('name', ProductPermissionSeeder::PERMISSIONS)->get()
            ->each(fn ($p) => Role::findByName('owner', 'web')->revokePermissionTo($p));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $owner = User::factory()->create();
        $owner->assignRole('owner');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/products/merge/catalog')->assertStatus(200);
        $this->postJson('/api/v1/products/merge/auto')->assertStatus(200);
    }
}
