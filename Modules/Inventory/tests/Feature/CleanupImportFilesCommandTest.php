<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Services\RackImport\RackImportBatchService;
use Modules\Product\Services\ImportBatchService;
use Modules\Purchase\Services\PurchaseOrderImportService;
use Modules\Sales\Services\SalesOrderImportBatchService;
use Tests\TestCase;

class CleanupImportFilesCommandTest extends TestCase
{
    public function test_it_removes_only_expired_import_uploads_from_all_import_roots(): void
    {
        Storage::fake('local');
        config()->set('filesystems.default', 'local');
        putenv('IMPORT_FILESYSTEM_DISK=local');

        try {
            $oldPaths = [
                ImportBatchService::DIR.'/old.xlsx',
                RackImportBatchService::DIR.'/old.xlsx',
                SalesOrderImportBatchService::DIR.'/old.xlsx',
                PurchaseOrderImportService::STORAGE_DIR.'/2026-09/old.xlsx',
            ];

            foreach ($oldPaths as $path) {
                Storage::disk('local')->put($path, 'old');
                touch(Storage::disk('local')->path($path), now()->subDays(8)->getTimestamp());
            }

            $freshPath = ImportBatchService::DIR.'/fresh.xlsx';
            Storage::disk('local')->put($freshPath, 'fresh');

            $this->artisan('imports:cleanup-files')
                ->assertSuccessful();

            foreach ($oldPaths as $path) {
                Storage::disk('local')->assertMissing($path);
            }
            Storage::disk('local')->assertExists($freshPath);
        } finally {
            putenv('IMPORT_FILESYSTEM_DISK');
        }
    }
}
