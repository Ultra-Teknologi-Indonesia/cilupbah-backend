<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverOrderAuditCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:order-audit
        {--run-id= : run_id hasil cutover:preflight}
        {--order-file=* : CSV daftar pesanan yang wajib dipertahankan}';

    protected $description = 'Memastikan order dari CSV ada di internal atau inbox queue, lalu menghitung order yang dipertahankan berdasarkan cutoff.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $report = $this->cutover()->auditOrders($this->runId(), (array) $this->option('order-file'));
            $this->report($report);

            return self::SUCCESS;
        });
    }
}
