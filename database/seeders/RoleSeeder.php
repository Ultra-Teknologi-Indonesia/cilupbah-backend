<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'owner' => 'Pemilik Sistem',
            'admin' => 'Administrator Sistem',
            'purchasing' => 'Staff Pembelian',
            'warehouse' => 'Staff Gudang',
            'picker' => 'Staff Picker Gudang',
            'checker' => 'Staff Checker Gudang',
            'handover' => 'Staff Serah Terima Gudang',
            'cs marketplace' => 'Customer Service Marketplace',
            'putaway' => 'Staff Penempatan Gudang',
            'kepala gudang' => 'Kepala Gudang',
            'leader outbound' => 'Leader Outbound Gudang',
            'leader inbound' => 'Leader Inbound Gudang'
        ];

        foreach ($roles as $role => $description) {
            $roleModel = Role::firstOrCreate(['name' => strtolower($role), 'guard_name' => 'web']);
            $roleModel->description = $description;
            $roleModel->save();
        }

        $permissions = [
            'view-user-history',
            'view-dashboard',
            'force-logout-user',
            'view-role',
            'create-role',
            'edit-role',
            'delete-role',
            'view-permission',
        ];

        foreach ($permissions as $permission) {
            \App\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
