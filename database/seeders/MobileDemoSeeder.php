<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MobileDemoSeeder extends Seeder
{
    /**
     * Seed akun demo untuk testing mobile lokal.
     *
     * Akun (semua password: password):
     *   - mobile@cilupbah.id     → Mobile Tester (owner)
     *   - putaway1@cilupbah.test → Putaway Satu  (role: putaway)
     *   - receiving1@cilupbah.test → Receiver Satu (role: warehouse)
     *   - picker1@cilupbah.test  → Picker Satu   (role: picker)
     *
     * Lengkap dengan 1 lokasi gudang + 1 rak default supaya bisa dipakai
     * seeder Inbound/Putaway/Picklist lain.
     */
    public function run(): void
    {
        $accounts = [
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
        ];

        foreach ($accounts as $acc) {
            $user = User::firstOrCreate(
                ['email' => $acc['email']],
                [
                    'name'     => $acc['name'],
                    'password' => Hash::make('password'),
                ]
            );

            try {
                $user->syncRoles([$acc['role']]);
            } catch (\Throwable $e) {
                $this->command->warn("Role '{$acc['role']}' belum ada untuk {$acc['email']}: {$e->getMessage()}");
            }
        }

        // Lokasi gudang demo — kolomnya location_code (bukan code) & is_warehouse boolean.
        $location = \Modules\Warehouse\Models\Location::firstOrCreate(
            ['location_code' => 'WH-MOB'],
            [
                'location_name' => 'Gudang Mobile',
                'is_warehouse'  => true,
                'is_active'     => true,
            ]
        );

        // Rak default — hanya bin_final_code + location_id yang wajib.
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
        $this->command->line('  Accounts (password: password):');
        foreach ($accounts as $acc) {
            $this->command->line("    - {$acc['email']}  ({$acc['role']})");
        }
        $this->command->line("  Location: {$location->location_code} — {$location->location_name}");
    }
}
