<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Modules\Auth\Support\PermissionCatalog;
use Spatie\Permission\PermissionRegistrar;

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
            $role->syncPermissions($permissionNames);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
