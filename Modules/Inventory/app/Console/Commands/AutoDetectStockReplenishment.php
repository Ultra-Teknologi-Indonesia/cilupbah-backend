<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Services\StockReplenishmentService;

class AutoDetectStockReplenishment extends Command
{
    protected $signature = 'replenishment:auto-detect
        {--dry-run : Hanya tampilkan preview, tidak mengubah request pending}';

    protected $description = 'Sinkronkan baris request restock yang sudah dipilih dari Monitor Stok.';

    public function handle(StockReplenishmentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->autoDetect($dryRun);

        if (! empty($result['skipped'])) {
            $this->warn($result['reason'] ?? 'Deteksi dilewati.');

            return self::FAILURE;
        }

        $shortages = $result['shortages'] ?? [];

        if (empty($shortages)) {
            $this->info('Tidak ada kekurangan stok di Gudang Kecil. Semua kebutuhan sudah tercover.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d SKU kekurangan stok di Gudang Kecil:',
            $dryRun ? '[DRY-RUN]' : 'Menemukan',
            count($shortages),
        ));

        foreach ($shortages as $s) {
            $this->line(sprintf(
                '  · %s need=%d available=%d in_flight=%d → shortage=%d',
                $s['sku'],
                $s['needed'],
                $s['available'],
                $s['in_flight'],
                $s['qty'],
            ));
        }

        if ($dryRun) {
            $this->line('Dry-run — tidak ada request yang dibuat.');

            return self::SUCCESS;
        }

        $request = $result['request'];
        if ($request) {
            $this->info(sprintf(
                'Baris request PENDING disinkronkan: %s (%d SKU terdeteksi di monitor).',
                $request->id,
                count($shortages),
            ));
        } else {
            $this->info('Tidak ada request PENDING baru. SKU baru harus dipilih dari Monitor Stok.');
        }

        return self::SUCCESS;
    }
}
