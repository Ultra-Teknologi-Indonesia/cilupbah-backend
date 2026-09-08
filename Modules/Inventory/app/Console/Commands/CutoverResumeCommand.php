<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverResumeCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:resume
        {--run-id= : run_id cutover}
        {--apply : Pastikan push stok dan fulfillment tetap mati; sync order tidak diubah}
        {--confirm= : Wajib RESUME-CUTOVER saat apply}';

    protected $description = 'Menyelesaikan cutover tanpa mengubah sync order; push stok dan fulfillment tetap dimatikan sampai handover diverifikasi.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('RESUME-CUTOVER');
            $count = $this->cutover()->resume($this->runId(), ! $apply);
            $this->line(($apply ? 'channel diverifikasi: ' : 'DRY-RUN channel yang akan diverifikasi: ').$count);

            return self::SUCCESS;
        });
    }
}
