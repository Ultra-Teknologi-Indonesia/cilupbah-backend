<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Database\Seeders\InventoryHistorySeeder;
use Modules\Product\Database\Seeders\ProductCatalogSeeder;

/**
 * Demo dataset: 30+ master products (all types, with S3 images) restricted to the
 * existing custom "Handphone & Aksesoris" leaf categories, plus a full 12-month
 * inventory/purchasing history (persediaan, pembelian, stok + history, barang
 * masuk, barang keluar) — NO sales orders (those come from channels).
 *
 * Run manually (local/staging only), no migrate:fresh needed:
 *   php artisan db:seed --class="Database\\Seeders\\DemoCatalogInventorySeeder"
 */
class DemoCatalogInventorySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'staging'])) {
            $this->command->error('DemoCatalogInventorySeeder only runs in local/staging. Aborting.');
            return;
        }

        // Suppress channel-sync / notification jobs that model observers may dispatch.
        Bus::fake();
        Queue::fake();

        // Ensure a seeder user exists (used as created_by across documents).
        User::firstOrCreate(
            ['email' => 'seeder@cilupbah.id'],
            ['name' => 'Seeder Demo', 'password' => Hash::make('password')]
        );

        $this->call(ProductCatalogSeeder::class);
        $this->call(InventoryHistorySeeder::class);

        $this->command->info('DemoCatalogInventorySeeder complete.');
    }
}
