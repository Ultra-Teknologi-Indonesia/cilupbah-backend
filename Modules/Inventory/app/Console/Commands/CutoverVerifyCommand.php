<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverVerifyCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:verify
        {--run-id= : run_id cutover}';

    protected $description = 'Memverifikasi order, transaksi, channel push, dan kondisi akhir cutover.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $report = $this->cutover()->verify($this->runId());
            $this->report($report);

            return (int) $report['blocking'] > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
