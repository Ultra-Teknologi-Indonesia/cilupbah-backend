<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverResetCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:reset
        {--run-id= : run_id hasil semua audit dry-run}
        {--purge-finance : Hapus invoice, payment, dan relasi finance untuk order terminal yang dihapus}
        {--apply : Terapkan penghapusan}
        {--confirm= : Wajib RESET-STOCK-DATA saat apply}';

    protected $description = 'Menghapus history stok dan transaksi cutover secara atomik, dengan menjaga master SKU, gudang, user, dan rak.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('RESET-STOCK-DATA');
            if ($apply && ! (bool) $this->option('purge-finance')) {
                throw new \RuntimeException('scope reset ini mencakup order paid, gunakan --purge-finance secara eksplisit agar invoice/payment order terminal ikut dihapus.');
            }
            $run = $this->cutover()->getRun($this->runId());
            if (! $apply) {
                $this->info('DRY-RUN: reset belum dijalankan, gunakan --apply --confirm=RESET-STOCK-DATA untuk eksekusi.');
                $this->report($this->cutover()->previewReset($run['run_id']));

                return self::SUCCESS;
            }
            $result = $this->cutover()->reset($run['run_id'], (bool) $this->option('purge-finance'));
            $this->info('reset cutover selesai');
            $this->report($result);

            return self::SUCCESS;
        });
    }
}
