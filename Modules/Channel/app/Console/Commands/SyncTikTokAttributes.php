<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokProductService;

class SyncTikTokAttributes extends Command
{
    protected $signature = 'tiktok:sync-attributes {shop_id} {--category= : Sync satu kategori (external_id)}';

    protected $description = 'Sync atribut TikTok untuk semua kategori yang di-mapping (atau satu kategori spesifik)';

    public function handle(TikTokProductService $service): int
    {
        $shopId = $this->argument('shop_id');
        $category = $this->option('category');

        try {
            if ($category) {
                $count = $service->syncCategoryAttributes($shopId, $category);
                $this->info("Kategori {$category}: {$count} atribut disinkronkan.");
            } else {
                $results = $service->syncAllMappedCategoryAttributes($shopId);
                foreach ($results as $extId => $count) {
                    $this->line("  Kategori {$extId}: {$count} atribut");
                }
                $this->info(count($results) . ' kategori disinkronkan.');
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
