<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Database\Seeders\ShopeeCategorySchemaSeeder;
use Modules\Inventory\Database\Seeders\InventoryHistorySeeder;
use Modules\Product\Database\Seeders\ProductCatalogSeeder;

class DemoCatalogInventorySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'staging'])) {
            $this->command->error('DemoCatalogInventorySeeder only runs in local/staging. Aborting.');
            return;
        }

        Bus::fake();
        Queue::fake();

        User::firstOrCreate(
            ['email' => 'seeder@cilupbah.id'],
            ['name' => 'Seeder Demo', 'password' => Hash::make('password')]
        );

        $this->call(ProductCatalogSeeder::class);
        $this->call(InventoryHistorySeeder::class);

        $this->call(ShopeeCategorySchemaSeeder::class);

        $this->command->info('DemoCatalogInventorySeeder complete.');
    }
}
