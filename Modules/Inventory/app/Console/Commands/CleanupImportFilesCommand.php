<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Modules\Inventory\Services\RackImport\RackImportBatchService;
use Modules\Product\Services\ImportBatchService;
use Modules\Purchase\Services\PurchaseOrderImportService;
use Modules\Sales\Services\SalesOrderImportBatchService;

final class CleanupImportFilesCommand extends Command
{
    protected $signature = 'imports:cleanup-files
        {--hours= : Retensi file import dalam jam; default dari IMPORT_FILE_RETENTION_HOURS}
        {--dry-run : Tampilkan file yang memenuhi syarat tanpa menghapus}';

    protected $description = 'Hapus file upload import yang sudah melewati retensi, tanpa menghapus riwayat import.';

    public function handle(): int
    {
        $hours = max(1, (int) ($this->option('hours') ?? config('file-retention.import_hours', 168)));
        $threshold = now()->subHours($hours)->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $candidates = 0;

        foreach ($this->targets() as $target) {
            $disk = Storage::disk($target['disk']);

            try {
                foreach ($disk->listContents($target['directory'], true) as $entry) {
                    if (! $entry instanceof FileAttributes || $entry->lastModified() >= $threshold) {
                        continue;
                    }

                    $candidates++;
                    $path = $entry->path();

                    if ($dryRun) {
                        $this->line("[DRY] {$target['label']}: {$path}");

                        continue;
                    }

                    if ($disk->delete($path)) {
                        $deleted++;
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('imports.cleanup.failed', [
                    'disk' => $target['disk'],
                    'directory' => $target['directory'],
                    'error' => $exception->getMessage(),
                ]);
                $this->warn("Tidak dapat memeriksa {$target['label']}; lanjut ke lokasi berikutnya.");
            }
        }

        $action = $dryRun ? 'Akan dihapus' : 'Dihapus';
        $this->info("{$action}: ".($dryRun ? $candidates : $deleted)." file import lebih tua dari {$hours} jam.");

        return self::SUCCESS;
    }

    private function targets(): array
    {
        return [
            [
                'label' => 'produk',
                'disk' => ImportBatchService::disk(),
                'directory' => ImportBatchService::DIR,
            ],
            [
                'label' => 'alokasi-rak',
                'disk' => RackImportBatchService::disk(),
                'directory' => RackImportBatchService::DIR,
            ],
            [
                'label' => 'pesanan-penjualan',
                'disk' => SalesOrderImportBatchService::disk(),
                'directory' => SalesOrderImportBatchService::DIR,
            ],
            [
                'label' => 'pesanan-pembelian',
                'disk' => PurchaseOrderImportService::disk(),
                'directory' => PurchaseOrderImportService::STORAGE_DIR,
            ],
        ];
    }
}
