<?php

namespace Modules\Supplier\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Supplier\Models\ContactCategory;

class SupplierDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Pelanggan Umum', 'description' => 'Kategori pelanggan umum'],
            ['name' => 'Reseller', 'description' => 'Kategori reseller'],
        ];

        foreach ($categories as $cat) {
            ContactCategory::firstOrCreate(
                ['name' => $cat['name']],
                ['description' => $cat['description']]
            );
        }
    }
}
