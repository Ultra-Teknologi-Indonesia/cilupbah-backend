<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;

class ProductDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            BrandSeeder::class,
            AttributeSeeder::class,
            CategoryAttributeSeeder::class,
            ChannelCategorySeeder::class,
            ProductPermissionSeeder::class,
        ]);
    }
}
