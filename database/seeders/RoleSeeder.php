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
            'owner',
            'admin',
            'purchasing',
            'warehouse',
            'picker',
            'checker',
            'handover',
            'cs marketplace',
            'putaway',
            'kepala gudang',
            'leader outbound',
            'leader inbound'
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
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
