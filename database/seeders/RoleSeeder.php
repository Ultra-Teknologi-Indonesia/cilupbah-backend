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
    }
}
