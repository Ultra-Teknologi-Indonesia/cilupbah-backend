<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

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
        $this->call(\Modules\Product\Database\Seeders\ProductDatabaseSeeder::class);
        $this->call(\Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder::class);
    }
}
