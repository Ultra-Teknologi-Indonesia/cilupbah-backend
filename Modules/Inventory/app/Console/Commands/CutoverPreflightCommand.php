<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverPreflightCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:preflight
        {--cutoff= : Cutoff dalam WIB, contoh 2026-09-03 18:00:00}
        {--locations= : Allowlist kode gudang dipisah koma, ALL tidak diizinkan}
        {--sku-manifest= : File manifest SKU dari tim gudang}
        {--stock-file=* : File baseline stok, boleh diulang per gudang}
        {--run-id= : Gunakan run_id yang sudah ada}
        {--apply : Tidak digunakan, preflight selalu read-only terhadap data bisnis}';

    protected $description = 'Membuat run cutover dan menjalankan pemeriksaan awal tanpa mengubah data bisnis.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $codes = $this->locationCodes();
            $runId = trim((string) $this->option('run-id'));
            if ($runId === '') {
                $created = $this->cutover()->createRun(
                    (string) $this->option('cutoff'),
                    $codes,
                    array_merge([(string) $this->option('sku-manifest')], (array) $this->option('stock-file')),
                );
                $runId = $created['run_id'];
                $this->info("run_id dibuat: {$runId}");
            }
            $run = $this->cutover()->getRun($runId);
            $report = $this->cutover()->preflight($runId, $run['location_ids']);
            $this->report($report);
            $this->info("preflight selesai, simpan run_id ini: {$runId}");

            return (int) ($report['blocking'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
        });
    }
}
