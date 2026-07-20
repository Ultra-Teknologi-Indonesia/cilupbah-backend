<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MobileDemoSeeder extends Seeder
{
    /**
     * Seed akun demo untuk testing mobile lokal (password: password).
     *
     * Akun berbasis ROLE (permissions ikut peta role di config/rbac.php):
     *   - mobile@cilupbah.id         → Mobile Tester    (role: owner)         → semua menu
     *   - putaway1@cilupbah.test     → Putaway Satu     (role: putaway)       → 3 menu warehouse
     *   - receiving1@cilupbah.test   → Receiver Satu    (role: warehouse)     → 3 menu warehouse
     *   - picker1@cilupbah.test      → Picker Satu      (role: picker)        → 3 menu warehouse
     *   - purchasing1@cilupbah.test  → Purchasing Satu  (role: purchasing)    → 0 menu warehouse
     *
     * Akun untuk TEST RBAC — tanpa role, hanya direct permission spesifik
     * supaya bisa lihat kombinasi menu tertentu di mobile:
     *   - rbac1menu@cilupbah.test  → hanya view-barang-masuk           → 1 menu (Penerimaan)
     *   - rbac2menu@cilupbah.test  → view-barang-masuk + view-penempatan → 2 menu (Penerimaan + Penempatan)
     *
     * Lengkap dengan 1 lokasi gudang + 1 rak default supaya bisa dipakai
     * seeder Inbound/Putaway/Picklist lain.
     */
    public function run(): void
    {
        // Akun dengan role standar
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
                $user->syncPermissions([]); // bersihkan direct perms kalau ada sisa
            } catch (\Throwable $e) {
                $this->command->warn("Role '{$acc['role']}' belum ada untuk {$acc['email']}: {$e->getMessage()}");
            }
        }

        // Akun test RBAC — tanpa role, permission langsung di-assign
        // supaya menu di mobile tampil sesuai jumlah yg diinginkan.
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
                $user->syncRoles([]);                            // reset role
                $user->syncPermissions($acc['permissions']);     // assign perms langsung
            } catch (\Throwable $e) {
                $this->command->warn("Gagal set permission untuk {$acc['email']}: {$e->getMessage()}");
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
