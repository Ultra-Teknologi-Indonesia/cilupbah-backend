<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverOrderAuditCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:order-audit
        {--run-id= : run_id hasil cutover:preflight}';

    protected $description = 'Menghitung order terminal yang dihapus dan order aktif yang dipertahankan berdasarkan cutoff.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $report = $this->cutover()->auditOrders($this->runId());
            $this->report($report);

            return self::SUCCESS;
        });
    }
}
