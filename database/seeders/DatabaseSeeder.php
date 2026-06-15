<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    public function run(): void
    {

        $this->call(RoleSeeder::class);

        $owner = User::firstOrCreate(
            ['email' => 'cilupbah@ultra-fit.id'],
            [
                'name' => 'Owner Cilupbah',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
            ]
        );

        $owner->assignRole('owner');

        $this->call(\Modules\Region\Database\Seeders\RegionDatabaseSeeder::class);
        $this->call(\Modules\Channel\Database\Seeders\ChannelDatabaseSeeder::class);
        $this->call(\Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder::class);
        // Master data untuk form produk: Chart of Accounts + daftar pajak.
        $this->call(\Modules\Finance\Database\Seeders\FinanceDatabaseSeeder::class);
        $this->call(\Modules\Tax\Database\Seeders\TaxDatabaseSeeder::class);

        if (app()->environment(['local', 'staging'])) {
            $this->call(TrackingItemsSeeder::class);
            $this->call(TrackingItemsCilupbahSeeder::class);
        }
    }
}
