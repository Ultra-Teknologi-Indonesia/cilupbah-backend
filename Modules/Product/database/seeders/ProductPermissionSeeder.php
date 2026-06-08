<?php

namespace Modules\Product\Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission untuk fitur Merge & Auto-Merge produk (Spatie Laravel Permission).
 *
 * Akses berbasis permission: selama user/role punya permission-nya, boleh akses.
 * Role `owner` otomatis lolos lewat Gate::before (lihat AppServiceProvider),
 * jadi tidak wajib di-assign — tapi tetap di-assign eksplisit untuk kejelasan.
 *
 * Mapping route → permission ada di routes/api.php & PLAN-PRODUCT-MERGE.md.
 */
class ProductPermissionSeeder extends Seeder
{
    /** Default role yang langsung diberi seluruh permission merge saat seeding. */
    private const DEFAULT_GRANTEES = ['owner', 'admin'];

    public const PERMISSIONS = [
        'view-product-merge',   // GET catalog / suggestions / applied
        'auto-merge-product',   // POST auto
        'merge-product',        // POST apply / bulk
        'unmerge-product',      // POST bulk-unmerge, DELETE master / {product}
        'hide-product',         // POST hide / unhide
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

        // Pastikan cache permission tidak basi setelah perubahan.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
