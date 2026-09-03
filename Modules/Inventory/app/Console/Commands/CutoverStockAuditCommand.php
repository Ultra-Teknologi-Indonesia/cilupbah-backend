<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverStockAuditCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:stock-audit
        {file : File baseline stok Jubelio}
        {--location= : Kode gudang untuk file ini}
        {--run-id= : run_id hasil cutover:preflight}';

    protected $description = 'Memvalidasi SKU, rak, qty, duplikasi, dan total stok baseline tanpa mengubah database.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $report = $this->cutover()->auditStock($this->runId(), (string) $this->argument('file'), strtoupper(trim((string) $this->option('location'))));
            $this->report($report);

            return (int) $report['blocking'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
