<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seed pemetaan kategori internal -> kategori channel (Shopee/Lazada/TikTok)
 * dari dump Jubelio yang sudah di-commit.
 *
 * WAJIB dijalankan SETELAH seeder kategori (CategorySeeder/DefaultCategorySeeder)
 * karena pencocokan berbasis path kategori internal.
 *
 * Delegasi ke command idempoten `channels:import-category-mappings` (fill-gap,
 * upsert) sehingga aman dijalankan ulang tanpa menimpa pemetaan manual.
 */
class CategoryChannelMappingSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('channels:import-category-mappings');

        if ($this->command) {
            $this->command->getOutput()->write(Artisan::output());
        }
    }
}
