<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Services\CategoryAttributeSyncService;

class MaterializeCategoryAttributes extends Command
{
    protected $signature = 'categories:materialize-attributes
        {category_id? : ID kategori internal; kosong = semua kategori yang sudah dipetakan}';

    protected $description = 'Materialkan category_attributes internal dari atribut channel (Fase A)';

    public function handle(CategoryAttributeSyncService $service): int
    {
        $categoryId = $this->argument('category_id');

        if ($categoryId) {
            $count = $service->materializeFromChannels((int) $categoryId);
            $this->info("{$count} atribut ditambahkan ke kategori {$categoryId}.");

            return self::SUCCESS;
        }

        $results = $service->materializeAllMapped();

        if (empty($results)) {
            $this->warn('Tidak ada kategori terpetakan. Petakan kategori + sinkron atribut channel dulu.');

            return self::SUCCESS;
        }

        foreach ($results as $cid => $count) {
            $this->line("  kategori {$cid}: +{$count} atribut");
        }
        $this->info('Selesai: +' . array_sum($results) . ' atribut dari ' . count($results) . ' kategori.');

        return self::SUCCESS;
    }
}
