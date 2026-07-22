<?php

namespace Modules\Warehouse\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

/**
 * Migrasi stok + history dari rak LAMA (di luar daftar kode rak otoritatif) ke
 * rak BARU, lalu hapus rak lama. Dipakai untuk membereskan env yang masih punya
 * rak dummy ber-stok/history (mis. staging) menuju layout bersih.
 *
 * Perilaku (sesuai keputusan):
 *   - Setiap SKU ber-stok di rak lama di-AUTO-ASSIGN ke satu rak baru KOSONG
 *     (WH-KECIL strict: 1 SKU/rak; WH-PUSAT bebas: tetap 1 rak/SKU utk kebersihan).
 *   - Stok (inventory) dipindah/di-merge ke rak baru.
 *   - History (inventory_movements/putaway_items/transfer_items/bin_transfer_items/
 *     inbound_receipts) di-RE-POINT per-item ke rak tujuan (item tanpa stok → DEFAULT).
 *   - Rak lama (sudah bebas referensi) DIHAPUS. Bin DEFAULT/inbound dipertahankan.
 *
 * Default = DRY-RUN. Tambahkan --commit untuk menerapkan.
 */
class MigrateStockToNewRacks extends Command
{
    protected $signature = 'warehouse:migrate-stock-to-new-racks
        {--location=* : Kode lokasi. Default: WH-PUSAT & WH-KECIL.}
        {--commit : Terapkan. Tanpa flag ini = dry-run (aman).}
        {--report-dir= : Direktori laporan CSV.}';

    protected $description = 'Pindah stok+history dari rak lama ke rak baru (auto-assign) lalu hapus rak lama. Dry-run default.';

    private const TARGETS = [
        'WH-PUSAT' => 'wh-pusat-bin-codes.csv',
        'WH-KECIL' => 'wh-kecil-bin-codes.csv',
    ];

    private bool $commit = false;
    private array $reports = [];

    /** @var array<int,array{0:string,1:string}> (tabel,kolom) FK ke location_bins, di-resolve dinamis. */
    private array $refCols = [];

    public function handle(): int
    {
        $this->commit = (bool) $this->option('commit');
        $locations = $this->option('location') ?: array_keys(self::TARGETS);
        $this->refCols = $this->resolveBinRefColumns();

        $mode = $this->commit ? '<fg=red;options=bold>COMMIT</>' : '<fg=green;options=bold>DRY-RUN</>';
        $this->line('');
        $this->line("Mode: {$mode}  |  Lokasi: ".implode(', ', $locations));

        $grand = [];
        foreach ($locations as $code) {
            $grand[$code] = $this->processLocation($code);
        }

        $this->printGrand($grand);
        $this->dumpReports();

        if (! $this->commit) {
            $this->warn('DRY-RUN selesai. Tidak ada perubahan. Tambahkan --commit untuk menerapkan.');
        }

        return self::SUCCESS;
    }

    private function processLocation(string $code): ?array
    {
        $this->line('');
        $this->line("── {$code} ──");

        if (! isset(self::TARGETS[$code])) {
            $this->warn('  Tidak ada daftar kode rak; dilewati.');
            return null;
        }
        $location = Location::where('location_code', $code)->first();
        if (! $location) {
            $this->warn('  Lokasi tidak ada; dilewati.');
            return null;
        }
        $defaultBin = LocationBin::where('location_id', $location->id)->where('is_inbound', true)->value('id');
        if (! $defaultBin) {
            $this->warn('  Bin DEFAULT/inbound tidak ada; dilewati (butuh target history item tanpa stok).');
            return null;
        }

        $path = dirname(__DIR__, 3).'/database/data/'.self::TARGETS[$code];
        $newCodes = array_flip(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));

        // rak lama = bukan di daftar baru & bukan inbound
        $oldBins = LocationBin::where('location_id', $location->id)
            ->where('is_inbound', false)
            ->get(['id', 'bin_final_code'])
            ->filter(fn ($b) => ! isset($newCodes[$b->bin_final_code]));
        $oldBinIds = $oldBins->pluck('id')->all();

        if (empty($oldBinIds)) {
            $this->info('  Tidak ada rak lama. Bersih.');
            return ['assigned' => 0, 'qty' => 0, 'history_repoint' => 0, 'old_deleted' => 0, 'old_total' => 0];
        }

        // item yang punya stok di rak lama
        $stockRows = DB::table('inventories')
            ->whereIn('bin_id', $oldBinIds)->where('on_hand', '>', 0)
            ->select('item_id', DB::raw('SUM(on_hand) as qty'))
            ->groupBy('item_id')->get();
        $itemsWithStock = $stockRows->pluck('qty', 'item_id')->all();

        // item yang punya referensi (stok 0 pun) di rak lama → butuh target history
        $refItems = $this->itemsReferencingOldBins($oldBinIds);
        $itemsNoStock = array_values(array_diff($refItems, array_keys($itemsWithStock)));

        // rak baru KOSONG utk di-assign (tak ada on_hand)
        $occupied = DB::table('inventories')->where('location_id', $location->id)
            ->where('on_hand', '>', 0)->distinct()->pluck('bin_id')->filter()->all();
        $emptyNewRacks = LocationBin::where('location_id', $location->id)
            ->where('is_inbound', false)
            ->whereIn('bin_final_code', array_keys($newCodes))
            ->whereNotIn('id', $occupied)
            ->orderBy('bin_final_code')
            ->pluck('id', 'bin_final_code');

        if ($emptyNewRacks->count() < count($itemsWithStock)) {
            $this->error(sprintf('  Rak baru kosong (%d) < SKU ber-stok (%d). Tidak cukup untuk assign.',
                $emptyNewRacks->count(), count($itemsWithStock)));
            return null;
        }

        // assign: item ber-stok → rak baru kosong (distinct); item tanpa stok → DEFAULT
        $itemTarget = [];
        $assignRows = [];
        $rackCodes = $emptyNewRacks->keys()->all();
        $rackIds = $emptyNewRacks->values()->all();
        $i = 0;
        foreach (array_keys($itemsWithStock) as $itemId) {
            $itemTarget[$itemId] = $rackIds[$i];
            $assignRows[] = ['item_id' => $itemId, 'rak_baru' => $rackCodes[$i], 'qty' => (int) $itemsWithStock[$itemId]];
            $i++;
        }
        foreach ($itemsNoStock as $itemId) {
            $itemTarget[$itemId] = $defaultBin; // hanya re-point history, tak ada stok
        }

        // hitung history yang akan di-repoint
        $histCount = $this->countHistory($oldBinIds);

        $this->reports["{$code}_assignment"] = $assignRows;

        $stats = [
            'old_total' => count($oldBinIds),
            'assigned' => count($itemsWithStock),
            'qty' => array_sum($itemsWithStock),
            'history_repoint' => $histCount,
            'item_history_only' => count($itemsNoStock),
            'old_deleted' => count($oldBinIds),
        ];

        if ($this->commit) {
            DB::transaction(function () use ($location, $oldBinIds, $itemTarget, $defaultBin) {
                $this->moveInventory($location->id, $oldBinIds, $itemTarget, $defaultBin);
                $this->repointHistory($oldBinIds, $itemTarget, $defaultBin);
                LocationBin::whereIn('id', $oldBinIds)->delete();
            });
        }

        $this->table(['Metrik', 'Nilai'], array_map(fn ($k, $v) => [$k, number_format($v)], array_keys($stats), array_values($stats)));

        return $stats;
    }

    /** Pindahkan SEMUA baris inventory di rak lama ke target (merge sesuai unique key). */
    private function moveInventory(string $locationId, array $oldBinIds, array $itemTarget, string $defaultBin): void
    {
        $rows = DB::table('inventories')->whereIn('bin_id', $oldBinIds)->get();
        foreach ($rows as $row) {
            $target = $itemTarget[$row->item_id] ?? $defaultBin;
            if ($target === $row->bin_id) {
                continue;
            }
            $existing = DB::table('inventories')
                ->where('item_id', $row->item_id)->where('location_id', $locationId)
                ->where('bin_id', $target)
                ->where('batch_no', $row->batch_no)->where('serial_no', $row->serial_no)
                ->first();
            if ($existing) {
                DB::table('inventories')->where('id', $existing->id)->update([
                    'on_hand' => $existing->on_hand + $row->on_hand,
                    'on_order' => $existing->on_order + $row->on_order,
                    'available' => ($existing->on_hand + $row->on_hand) - ($existing->on_order + $row->on_order),
                    'updated_at' => now(),
                ]);
                DB::table('inventories')->where('id', $row->id)->delete();
            } else {
                DB::table('inventories')->where('id', $row->id)->update(['bin_id' => $target, 'updated_at' => now()]);
            }
        }
    }

    /**
     * Re-point kolom bin di semua tabel history ke rak tujuan (per-item; tabel tanpa
     * item_id → DEFAULT). Coba bulk; jika bentrok unique constraint, fallback per-baris
     * (re-point yang bisa, buang baris duplikat) supaya rak lama pasti bebas referensi.
     */
    private function repointHistory(array $oldBinIds, array $itemTarget, string $defaultBin): void
    {
        $byTarget = [];
        foreach ($itemTarget as $item => $target) {
            $byTarget[$target][] = $item;
        }

        foreach ($this->refCols as [$table, $col]) {
            $hasItem = Schema::hasColumn($table, 'item_id');
            try {
                DB::transaction(function () use ($table, $col, $oldBinIds, $byTarget, $defaultBin, $hasItem) {
                    if ($hasItem) {
                        foreach ($byTarget as $target => $items) {
                            DB::table($table)->whereIn($col, $oldBinIds)->whereIn('item_id', $items)
                                ->update([$col => $target]);
                        }
                    }
                    DB::table($table)->whereIn($col, $oldBinIds)->update([$col => $defaultBin]);
                });
            } catch (\Throwable $e) {
                $this->repointRowWise($table, $col, $oldBinIds, $itemTarget, $defaultBin, $hasItem);
            }
        }
    }

    /** Fallback per-baris: re-point tiap baris; jika bentrok unique → hapus baris (duplikat). */
    private function repointRowWise(string $table, string $col, array $oldBinIds, array $itemTarget, string $defaultBin, bool $hasItem): void
    {
        if (! Schema::hasColumn($table, 'id')) {
            // tanpa PK: tak bisa re-point aman → hapus referensi rak lama
            DB::table($table)->whereIn($col, $oldBinIds)->delete();
            return;
        }

        $select = $hasItem ? ['id', 'item_id'] : ['id'];
        foreach (DB::table($table)->whereIn($col, $oldBinIds)->get($select) as $row) {
            $target = $hasItem ? ($itemTarget[$row->item_id] ?? $defaultBin) : $defaultBin;
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $row->id)->update([$col => $target]));
            } catch (\Throwable $e) {
                DB::table($table)->where('id', $row->id)->delete();
            }
        }
    }

    private function itemsReferencingOldBins(array $oldBinIds): array
    {
        $items = [];
        foreach ($this->refCols as [$table, $col]) {
            if (! Schema::hasColumn($table, 'item_id')) {
                continue;
            }
            $found = DB::table($table)->whereIn($col, $oldBinIds)->distinct()->pluck('item_id')->all();
            $items = array_merge($items, $found);
        }
        return array_values(array_unique(array_filter($items)));
    }

    private function countHistory(array $oldBinIds): int
    {
        $n = 0;
        foreach ($this->refCols as [$table, $col]) {
            $n += DB::table($table)->whereIn($col, $oldBinIds)->count();
        }
        return $n;
    }

    /**
     * Semua (tabel,kolom) yang FK ke location_bins, KECUALI inventories (ditangani
     * moveInventory) dan locations.default_bin_id (jangan disentuh). Resolve dinamis
     * dari information_schema agar tak ada tabel yang terlewat.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function resolveBinRefColumns(): array
    {
        $rows = DB::select(
            "SELECT tc.table_name AS t, kcu.column_name AS c
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
             JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name = ccu.constraint_name
             WHERE tc.constraint_type = 'FOREIGN KEY' AND ccu.table_name = 'location_bins'"
        );

        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            if (in_array($r->t, ['inventories', 'locations'], true)) {
                continue;
            }
            $key = $r->t.'.'.$r->c;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [$r->t, $r->c];
        }
        return $out;
    }

    private function printGrand(array $grand): void
    {
        $this->line('');
        $this->line('<options=bold>== RINGKASAN TOTAL ==</>');
        $rows = [];
        foreach ($grand as $code => $s) {
            $rows[] = $s === null
                ? [$code, '-', '-', '-', '-']
                : [$code, number_format($s['assigned']), number_format($s['qty']), number_format($s['history_repoint']), number_format($s['old_deleted'])];
        }
        $this->table(['Lokasi', 'SKU di-assign', 'Qty pindah', 'History re-point', 'Rak lama dihapus'], $rows);
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
            if (empty($rows)) {
                continue;
            }
            $p = "{$dir}/{$name}_{$stamp}.csv";
            $this->line(sprintf('  %-24s %5d baris → %s', $name, count($rows), $p));
            $fh = @fopen($p, 'w');
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
