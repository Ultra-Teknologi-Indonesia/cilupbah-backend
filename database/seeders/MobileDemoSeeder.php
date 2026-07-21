<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MobileDemoSeeder extends Seeder
{

    public function run(): void
    {

        $roleAccounts = [
            [
                'email' => 'mobile@cilupbah.id',
                'name'  => 'Mobile Tester',
                'role'  => 'owner',
            ],
            [
                'email' => 'putaway1@cilupbah.test',
                'name'  => 'Putaway Satu',
                'role'  => 'putaway',
            ],
            [
                'email' => 'receiving1@cilupbah.test',
                'name'  => 'Receiver Satu',
                'role'  => 'warehouse',
            ],
            [
                'email' => 'picker1@cilupbah.test',
                'name'  => 'Picker Satu',
                'role'  => 'picker',
            ],
            [
                'email' => 'purchasing1@cilupbah.test',
                'name'  => 'Purchasing Satu',
                'role'  => 'purchasing',
            ],
        ];

        foreach ($roleAccounts as $acc) {
            $user = User::firstOrCreate(
                ['email' => $acc['email']],
                [
                    'name'     => $acc['name'],
                    'password' => Hash::make('password'),
                ]
            );

            try {
                $user->syncRoles([$acc['role']]);
                $user->syncPermissions([]); 
            } catch (\Throwable $e) {
                $this->command->warn("Role '{$acc['role']}' belum ada untuk {$acc['email']}: {$e->getMessage()}");
            }
        }

        $permAccounts = [
            [
                'email'       => 'rbac1menu@cilupbah.test',
                'name'        => 'RBAC 1 Menu (Penerimaan)',
                'permissions' => ['view-barang-masuk'],
            ],
            [
                'email'       => 'rbac2menu@cilupbah.test',
                'name'        => 'RBAC 2 Menu (Penerimaan + Penempatan)',
                'permissions' => ['view-barang-masuk', 'view-penempatan'],
            ],
        ];

        foreach ($permAccounts as $acc) {
            $user = User::firstOrCreate(
                ['email' => $acc['email']],
                [
                    'name'     => $acc['name'],
                    'password' => Hash::make('password'),
                ]
            );

            try {
                $user->syncRoles([]);                            
                $user->syncPermissions($acc['permissions']);     
            } catch (\Throwable $e) {
                $this->command->warn("Gagal set permission untuk {$acc['email']}: {$e->getMessage()}");
            }
        }

        $location = \Modules\Warehouse\Models\Location::firstOrCreate(
            ['location_code' => 'WH-MOB'],
            [
                'location_name' => 'Gudang Mobile',
                'is_warehouse'  => true,
                'is_active'     => true,
            ]
        );

        \Modules\Warehouse\Models\LocationBin::firstOrCreate(
            ['bin_final_code' => 'WH-MOB-A1'],
            [
                'location_id'           => $location->id,
                'bin_code'              => 'A1',
                'is_inbound'            => false,
                'is_stock_acknowledged' => true,
            ]
        );

        $this->command->info('Mobile Demo Seeder completed successfully!');
        $this->command->line('  Akun berbasis role (password: password):');
        foreach ($roleAccounts as $acc) {
            $this->command->line("    - {$acc['email']}  (role: {$acc['role']})");
        }
        $this->command->line('  Akun test RBAC — direct permission:');
        foreach ($permAccounts as $acc) {
            $perms = implode(', ', $acc['permissions']);
            $this->command->line("    - {$acc['email']}  → {$perms}");
        }
        $this->command->line("  Location: {$location->location_code} — {$location->location_name}");
    }
}
