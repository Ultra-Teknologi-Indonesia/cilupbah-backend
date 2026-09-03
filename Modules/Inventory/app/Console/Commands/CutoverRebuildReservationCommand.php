<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverRebuildReservationCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:rebuild-reservation
        {--run-id= : run_id hasil reset dan import}
        {--apply : Rebuild on_order dan ledger order reserve}
        {--confirm= : Wajib REBUILD-RESERVATION saat apply}';

    protected $description = 'Membangun ulang on_order dan ledger reservation dari order pending/reserved yang dipertahankan.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('REBUILD-RESERVATION');
            $runId = $this->runId();
            if (! $apply) {
                $this->info('DRY-RUN: reservation belum dibangun, berikut estimasi berdasarkan order pending/reserved.');
                $this->report($this->cutover()->previewReservations($runId));

                return self::SUCCESS;
            }
            $this->report($this->cutover()->rebuildReservations($runId));

            return self::SUCCESS;
        });
    }
}
