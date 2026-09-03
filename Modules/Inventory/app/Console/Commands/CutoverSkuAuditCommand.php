<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverSkuAuditCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:sku-audit
        {manifest : File daftar SKU lengkap dari tim gudang, termasuk SKU qty 0}
        {--run-id= : run_id hasil cutover:preflight}';

    protected $description = 'Membandingkan manifest SKU gudang dengan master SKU dan alokasi rak tanpa mengubah database.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $run = $this->cutover()->getRun($this->runId());
            $report = $this->cutover()->auditSku($run['run_id'], (string) $this->argument('manifest'), $run['location_ids']);
            $this->report($report);

            return (int) $report['blocking'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
