<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Support\Facades\Artisan;

final class CutoverImportStockCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:import-stock
        {file : File baseline stok Jubelio}
        {--location= : Kode gudang untuk file ini}
        {--run-id= : run_id hasil preflight dan reset}
        {--zero-missing : Nolkan pasangan SKU-rak yang tidak ada di file}
        {--apply : Terapkan import setelah audit}
        {--confirm= : Wajib IMPORT-STOCK saat apply}';

    protected $description = 'Menjalankan audit baseline lalu mengimpor on_hand, tanpa mengimpor on_order.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('IMPORT-STOCK');
            $runId = $this->runId();
            $file = (string) $this->argument('file');
            $location = strtoupper(trim((string) $this->option('location')));
            $audit = $this->cutover()->auditStock($runId, $file, $location);
            $this->report($audit);
            if ((int) $audit['blocking'] > 0) {
                return self::FAILURE;
            }
            if (! $apply) {
                $this->info('DRY-RUN: import belum dijalankan, gunakan --apply --confirm=IMPORT-STOCK.');

                return self::SUCCESS;
            }
            $result = $this->cutover()->importStock($runId, $file, $location, (bool) $this->option('zero-missing'));
            $this->line(Artisan::output());
            $this->info('import baseline selesai');
            $this->report($result);

            return self::SUCCESS;
        });
    }
}
