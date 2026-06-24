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

        $this->call(\Modules\Finance\Database\Seeders\FinanceDatabaseSeeder::class);
        $this->call(\Modules\Tax\Database\Seeders\TaxDatabaseSeeder::class);

        $this->call(\Modules\Product\Database\Seeders\CategorySeeder::class);
        $this->call(\Modules\Product\Database\Seeders\DefaultCategorySeeder::class);
        $this->call(\Modules\Product\Database\Seeders\BrandSeeder::class);

        $this->call(\Modules\Inventory\Database\Seeders\InventoryDatabaseSeeder::class);
        $this->call(\Modules\Supplier\Database\Seeders\SupplierDatabaseSeeder::class);

        if (app()->environment(['local', 'staging'])) {
            $this->call(TrackingItemsSeeder::class);
            $this->call(TrackingItemsCilupbahSeeder::class);
        }
    }
}
