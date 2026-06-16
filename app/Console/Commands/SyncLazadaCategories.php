<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\LazadaProductService;

class SyncLazadaCategories extends Command
{
    protected $signature = 'lazada:sync-categories {shop_id : Seller ID Lazada (channel_shops.shop_id)}';

    protected $description = 'Sinkronkan pohon kategori Lazada ke channel_categories';

    public function handle(LazadaProductService $service): int
    {
        try {
            $count = $service->syncCategoryTree($this->argument('shop_id'));
        } catch (\Throwable $e) {
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$count} kategori Lazada disinkronkan.");

        return self::SUCCESS;
    }
}
