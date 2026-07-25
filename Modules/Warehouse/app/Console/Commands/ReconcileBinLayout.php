<?php

namespace Modules\Warehouse\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinLayoutImporter;

class ReconcileBinLayout extends Command
{
    protected $signature = 'warehouse:reconcile-bin-layout
        {--location=* : Kode lokasi (boleh banyak). Default: WH-PUSAT & WH-KECIL.}
        {--commit : Terapkan perubahan. Tanpa flag ini = dry-run (aman).}
        {--report-dir= : Direktori output laporan CSV (default: storage/app/bin-migration).}';

    protected $description = 'Rekonsiliasi kode rak gudang ke daftar otoritatif (buat baru + hapus dummy aman). Dry-run default.';

    private const TARGETS = [
        'WH-PUSAT' => 'wh-pusat-bin-codes.csv',
        'WH-KECIL' => 'wh-kecil-bin-codes.csv',
    ];

    private const REF_TABLES = [
        ['inventories', ['bin_id']],
        ['inventory_movements', ['bin_id']],
        ['inbound_receipts', ['bin_id']],
        ['inventory_transfer_items', ['source_bin_id', 'destination_bin_id']],
        ['putaway_items', ['source_bin_id', 'destination_bin_id']],
        ['bin_transfer_items', ['source_bin_id', 'destination_bin_id']],
    ];

    private bool $commit = false;

    private array $reports = [];

    private array $refCols = [];

    public function handle(): int
    {
        $this->commit = (bool) $this->option('commit');
        $locations = $this->option('location') ?: array_keys(self::TARGETS);

        $this->refCols = $this->resolveRefColumns();

        $mode = $this->commit ? '<fg=red;options=bold>COMMIT (menulis DB)</>' : '<fg=green;options=bold>DRY-RUN (tidak menulis)</>';
        $this->line('');
        $this->line("Mode : {$mode}");
        $this->line('Lokasi target: '.implode(', ', $locations));
        $this->line('');

        $grand = [];
        foreach ($locations as $code) {
            $grand[$code] = $this->processLocation($code);
        }

        $this->printGrandSummary($grand);
        $this->dumpReports();

        if (! $this->commit) {
            $this->line('');
            $this->warn('DRY-RUN selesai. Tidak ada perubahan. Jalankan ulang dengan --commit untuk menerapkan.');
            $this->line('Catatan: rak lama ber-stok/history TIDAK dihapus di sini (lihat laporan *_blocked) — perlu migrasi stok/history dulu.');
        } else {
            $this->info('COMMIT selesai.');
        }

        return self::SUCCESS;
    }

    private function processLocation(string $code): ?array
    {
        $this->line("── {$code} ──");

        if (! isset(self::TARGETS[$code])) {
            $this->warn("  Tidak ada file kode rak bundled untuk '{$code}', dilewati.");
            return null;
        }

        $location = Location::where('location_code', $code)->first();
        if (! $location) {
            $this->warn("  Lokasi '{$code}' tidak ada, dilewati.");
            return null;
        }

        $path = dirname(__DIR__, 3).'/database/data/'.self::TARGETS[$code];
        if (! is_readable($path)) {
            $this->warn("  File tidak ditemukan: {$path}");
            return null;
        }

        $newCodes = array_values(array_unique(array_filter(array_map(
            'trim',
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        ))));
        $newSet = array_flip($newCodes);

        $existing = LocationBin::where('location_id', $location->id)
            ->get(['id', 'bin_final_code', 'is_inbound']);

        $existingCodes = $existing->pluck('bin_final_code')->all();
        $existingSet = array_flip($existingCodes);

        $layout = app(BinLayoutImporter::class)->import($location, $newCodes, $this->commit);

        $systemPreserved = 0;
        $deletable = 0;
        $blocked = 0;
        $keep = 0;

        foreach ($existing as $bin) {
            if (isset($newSet[$bin->bin_final_code])) {
                $keep++;
                continue; 
            }
            if ($bin->is_inbound) {
                $systemPreserved++;
                continue; 
            }

            $refs = $this->refsFor($bin->id);
            $hasStock = $this->hasStock($bin->id);

            if (empty($refs)) {
                $deletable++;
                $this->reports["{$code}_rak_dihapus"][] = ['kode_rak' => $bin->bin_final_code];
                if ($this->commit) {
                    try {
                        LocationBin::where('id', $bin->id)->delete();
                    } catch (\Throwable $e) {
                        $this->reports["{$code}_gagal_hapus"][] = [
                            'kode_rak' => $bin->bin_final_code,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            } else {
                $blocked++;
                $this->reports["{$code}_rak_blocked"][] = [
                    'kode_rak' => $bin->bin_final_code,
                    'ada_stok' => $hasStock ? 'ya' : 'tidak',
                    'referensi' => json_encode($refs),
                ];
            }
        }

        $stats = [
            'kode_baru_dibuat' => $layout['created'] ?: count($layout['new_codes']),
            'kode_sudah_ada' => count($newCodes) - count($layout['new_codes']),
            'zona_dibuat' => $layout['zones_created'],
            'rak_dipertahankan_in_set' => $keep,
            'dummy_dihapus' => $deletable,
            'dummy_blocked_stok_history' => $blocked,
            'system_default_dipertahankan' => $systemPreserved,
        ];

        $this->table(['Metrik', 'Nilai'], array_map(
            fn ($k, $v) => [$k, number_format($v).($this->commit ? '' : ($k === 'kode_baru_dibuat' || $k === 'dummy_dihapus' ? ' (prediksi)' : ''))],
            array_keys($stats),
            array_values($stats)
        ));

        return $stats;
    }

    private function refsFor(string $binId): array
    {
        $hits = [];
        foreach ($this->refCols as [$table, $col]) {
            $n = DB::table($table)->where($col, $binId)->count();
            if ($n > 0) {
                $hits[$table.'.'.$col] = $n;
            }
        }
        return $hits;
    }

    private function hasStock(string $binId): bool
    {
        if (! Schema::hasTable('inventories')) {
            return false;
        }
        return DB::table('inventories')
            ->where('bin_id', $binId)
            ->where(fn ($q) => $q->where('on_hand', '>', 0)->orWhere('on_order', '>', 0))
            ->exists();
    }

    private function resolveRefColumns(): array
    {
        $cols = [];
        foreach (self::REF_TABLES as [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $c) {
                if (Schema::hasColumn($table, $c)) {
                    $cols[] = [$table, $c];
                }
            }
        }
        return $cols;
    }

    private function printGrandSummary(array $grand): void
    {
        $this->line('');
        $this->line('<options=bold>== RINGKASAN TOTAL ==</>');
        $rows = [];
        foreach ($grand as $code => $s) {
            if ($s === null) {
                $rows[] = [$code, '-', '-', '-', '-'];
                continue;
            }
            $rows[] = [
                $code,
                number_format($s['kode_baru_dibuat']),
                number_format($s['rak_dipertahankan_in_set']),
                number_format($s['dummy_dihapus']),
                number_format($s['dummy_blocked_stok_history']),
            ];
        }
        $this->table(['Lokasi', 'Kode baru', 'Dipertahankan', 'Dummy dihapus', 'Blocked (stok/history)'], $rows);
    }

    private function dumpReports(): void
    {
        if (empty($this->reports)) {
            return;
        }
        $dir = (string) ($this->option('report-dir') ?: storage_path('app/bin-migration'));
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $stamp = now()->format('Ymd_His');

        $this->line('');
        $this->line('<options=bold>== LAPORAN ==</>');
        foreach ($this->reports as $name => $rows) {
            $path = "{$dir}/{$name}_{$stamp}.csv";
            $this->line(sprintf('  %-28s %6d baris → %s', $name, count($rows), $path));
            $fh = @fopen($path, 'w');
            if ($fh === false) {
                continue;
            }
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
        }
    }
}
