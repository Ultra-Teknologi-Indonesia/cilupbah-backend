<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\ShopeeProductService;

class SyncShopeeCategories extends Command
{
    protected $signature = 'shopee:sync-categories {shop_id : Shop ID Shopee (channel_shops.shop_id)}';

    protected $description = 'Sinkronkan pohon kategori Shopee ke channel_categories';

    public function handle(ShopeeProductService $service): int
    {
        try {
            $count = $service->syncCategoryTree($this->argument('shop_id'));
        } catch (\Throwable $e) {
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$count} kategori Shopee disinkronkan.");

        return self::SUCCESS;
    }
}
