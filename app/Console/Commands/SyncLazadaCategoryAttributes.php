<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\LazadaProductService;

class SyncLazadaCategoryAttributes extends Command
{
    protected $signature = 'lazada:sync-category-attributes
        {shop_id : Seller ID Lazada (channel_shops.shop_id)}
        {category_id? : external_id kategori Lazada; kosong = semua kategori yang sudah dipetakan}';

    protected $description = 'Sinkronkan spec atribut Lazada per kategori ke channel_attributes';

    public function handle(LazadaProductService $service): int
    {
        $shopId = $this->argument('shop_id');
        $categoryId = $this->argument('category_id');

        try {
            if ($categoryId) {
                $count = $service->syncCategoryAttributes($shopId, $categoryId);
                $this->info("{$count} atribut disinkronkan untuk kategori {$categoryId}.");

                return self::SUCCESS;
            }

            $results = $service->syncAllMappedCategoryAttributes($shopId);
        } catch (\Throwable $e) {
            $this->error('Gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (empty($results)) {
            $this->warn('Tidak ada kategori Lazada yang dipetakan. Petakan kategori dulu di category_channel_mappings.');

            return self::SUCCESS;
        }

        foreach ($results as $extId => $count) {
            $this->line("  kategori {$extId}: {$count} atribut");
        }
        $this->info('Selesai: ' . array_sum($results) . ' atribut dari ' . count($results) . ' kategori.');

        return self::SUCCESS;
    }
}
