<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Modules\Auth\Support\PermissionCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * Membuat seluruh permission dari katalog RBAC (config/rbac.php) dan memberi
 * grant default per role. Bersifat additif & idempotent:
 *  - permission dibuat dengan firstOrCreate
 *  - grant default via givePermissionTo (tidak menghapus grant manual dari UI)
 *
 * Jalankan sesudah RoleSeeder (butuh role sudah ada). owner tidak diberi grant
 * karena bypass total lewat Gate::before.
 */
class RbacPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::allPermissionNames() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (PermissionCatalog::config()['defaults'] ?? [] as $roleName => $tokens) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role === null) {
                continue;
            }

            $permissionNames = PermissionCatalog::resolveGrants($tokens);
            if ($permissionNames !== []) {
                $role->givePermissionTo($permissionNames);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
