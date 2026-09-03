<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverResumeCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:resume
        {--run-id= : run_id cutover}
        {--apply : Nyalakan kembali order sync, push stok tetap mati}
        {--confirm= : Wajib RESUME-CUTOVER saat apply}';

    protected $description = 'Membuka kembali intake order, sementara push stok tetap dimatikan sampai handover diverifikasi.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('RESUME-CUTOVER');
            $count = $this->cutover()->resume($this->runId(), ! $apply);
            $this->line(($apply ? 'channel diresume: ' : 'DRY-RUN channel yang akan diresume: ').$count);

            return self::SUCCESS;
        });
    }
}
