<?php

namespace Modules\Product\Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class ProductPermissionSeeder extends Seeder
{

    private const DEFAULT_GRANTEES = ['owner', 'admin'];

    public const PERMISSIONS = [
        'view-product-merge',   
        'auto-merge-product',   
        'merge-product',        
        'unmerge-product',      
        'hide-product',         
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::DEFAULT_GRANTEES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo(self::PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
