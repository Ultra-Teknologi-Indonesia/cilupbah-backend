<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverOpenOrderIntakeCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:open-order-intake
        {--run-id= : run_id hasil cutover}
        {--apply : Aktifkan penerimaan order marketplace}
        {--confirm= : Wajib OPEN-ORDER-INTAKE saat apply}';

    protected $description = 'Membuka kembali intake order setelah reset cutover, tanpa mengaktifkan stock push.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('OPEN-ORDER-INTAKE');
            $runId = $this->runId();

            $count = $this->cutover()->openOrderIntake($runId, ! $apply);
            $this->info($apply
                ? "intake order dibuka untuk {$count} toko"
                : "DRY-RUN: {$count} toko akan dibuka intake order; stock push tetap mati.");

            return self::SUCCESS;
        });
    }
}
