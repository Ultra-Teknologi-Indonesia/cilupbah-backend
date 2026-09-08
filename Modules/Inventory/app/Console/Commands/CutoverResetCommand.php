<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverResetCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:reset
        {--run-id= : run_id hasil semua audit dry-run}
        {--purge-finance : Wajib; hapus invoice, payment, dan relasi finance untuk semua order di scope reset}
        {--apply : Terapkan penghapusan}
        {--confirm= : Wajib RESET-STOCK-DATA saat apply}';

    protected $description = 'Menghapus history stok dan dokumen operasional secara atomik, sambil menjaga master SKU, gudang, user, rak, serta order Excel/order yang lebih baru.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('RESET-STOCK-DATA');
            if ($apply && ! (bool) $this->option('purge-finance')) {
                throw new \RuntimeException('gunakan --purge-finance agar invoice, payment, dan relasi finance semua order dalam scope ikut dibersihkan.');
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
