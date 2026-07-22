<?php

namespace Modules\Warehouse\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\LocationZone;

/**
 * Migrasi layout + alokasi rak dari export Jubelio (kolom: SKU, Lokasi, Rak).
 *
 * Strategi (lihat PLANNING-IMPORT-LAYOUT-GUDANG-ZONA.md):
 *  - LAYOUT  : semua kode rak unik dibuat (aditif, existing-wins) + zona auto-create.
 *  - ALOKASI : hanya rak 1-SKU yang dipindah stoknya (patuh aturan WH-KECIL 1 rak = 1 SKU).
 *              Rak multi-SKU (GK shelf / KANTOR / OUTBOUND) hanya dibuat kodenya, TIDAK dialokasi,
 *              lalu dilaporkan untuk penanganan manual.
 *  - Bin DEFAULT/inbound tidak disentuh. SKU cilupbah ber-stok yang tak ada di file dilaporkan.
 *
 * Default = DRY-RUN (tidak menulis apa pun). Tambahkan --commit untuk menerapkan.
 */
class ImportBinAllocation extends Command
{
    protected $signature = 'warehouse:import-bin-allocation
        {--file= : Path file CSV export Jubelio (kolom SKU, Lokasi, Rak).}
        {--location=WH-KECIL : location_code gudang tujuan.}
        {--commit : Terapkan perubahan. Tanpa flag ini = dry-run (aman, tidak menulis).}
        {--report-dir= : Direktori output laporan CSV (default: storage/app/bin-migration).}';

    protected $description = 'Import layout + alokasi rak dari CSV Jubelio (dry-run secara default).';

    private const CREATED_BY = 'system:bin-allocation-migration';

    private bool $commit = false;

    /** @var array<string,array<string,int>> laporan bucket => rows */
    private array $reports = [];

    public function handle(InventoryRepository $inventoryRepo, InventoryMovementRepository $movementRepo): int
    {
        $this->commit = (bool) $this->option('commit');
        $file = (string) $this->option('file');

        if ($file === '' || ! is_readable($file)) {
            $this->error("File tidak ditemukan / tidak terbaca: {$file}");
            return self::FAILURE;
        }

        $location = Location::where('location_code', $this->option('location'))->first();
        if (! $location) {
            $this->error("Lokasi dengan code '{$this->option('location')}' tidak ditemukan.");
            return self::FAILURE;
        }

        $mode = $this->commit ? '<fg=red;options=bold>COMMIT (menulis DB)</>' : '<fg=green;options=bold>DRY-RUN (tidak menulis)</>';
        $this->line('');
        $this->line("Mode        : {$mode}");
        $this->line("Lokasi      : {$location->location_code} ({$location->location_name})");
        $this->line('Aturan rak  : '.($location->enforcesStrictBinSku() ? '1 rak = 1 SKU (STRICT)' : 'bebas'));
        $this->line("File        : {$file}");
        $this->line('');

        // ---- Phase A: parse CSV -------------------------------------------------
        [$rows, $parseErrors] = $this->parseCsv($file, $location->location_name);
        if (! empty($parseErrors)) {
            foreach ($parseErrors as $e) {
                $this->reports['baris_lokasi_lain'][] = $e;
            }
        }
        if (empty($rows)) {
            $this->error('Tidak ada baris valid untuk lokasi ini di file.');
            $this->dumpReports();
            return self::FAILURE;
        }

        // rak => set SKU ; sku => rak (1 SKU = 1 rak diasumsikan; bentrok dilaporkan)
        $rakToSkus = [];
        $skuToRak = [];
        foreach ($rows as $r) {
            $rakToSkus[$r['rak']][$r['sku']] = true;
            if (isset($skuToRak[$r['sku']]) && $skuToRak[$r['sku']] !== $r['rak']) {
                $this->reports['sku_di_banyak_rak'][] = [
                    'sku' => $r['sku'],
                    'rak_1' => $skuToRak[$r['sku']],
                    'rak_2' => $r['rak'],
                ];
            }
            $skuToRak[$r['sku']] = $r['rak'];
        }

        // ---- Phase B: klasifikasi rak ------------------------------------------
        $singleSkuRaks = [];   // rak_code => sku
        $multiSkuRaks = [];     // rak_code => [sku,...]
        foreach ($rakToSkus as $rak => $skuSet) {
            $skus = array_keys($skuSet);
            if (count($skus) === 1) {
                $singleSkuRaks[$rak] = $skus[0];
            } else {
                $multiSkuRaks[$rak] = $skus;
            }
        }
        foreach ($multiSkuRaks as $rak => $skus) {
            $bucket = $this->isSpecialRak($rak) ? 'rak_spesial_multi_sku' : 'rak_shelf_multi_sku';
            $this->reports[$bucket][] = ['rak' => $rak, 'jumlah_sku' => count($skus), 'skus' => implode('|', $skus)];
        }

        $allRaks = array_keys($rakToSkus);

        // ---- Phase C: resolusi SKU -> variant ----------------------------------
        $allSkus = array_keys($skuToRak);
        $variantMap = ProductVariant::whereIn('sku', $allSkus)->pluck('id', 'sku')->all();
        $missingSkus = array_values(array_diff($allSkus, array_keys($variantMap)));
        foreach ($missingSkus as $sku) {
            $this->reports['sku_tak_ada_di_produk'][] = ['sku' => $sku, 'rak_tujuan' => $skuToRak[$sku] ?? '-'];
        }

        // ---- Phase D: LAYOUT (buat semua kode rak) -----------------------------
        $existingCodes = LocationBin::where('location_id', $location->id)
            ->pluck('id', 'bin_final_code')->all();
        $codeToBinId = $existingCodes; // code => binId (dilengkapi saat commit)
        $newCodes = array_values(array_diff($allRaks, array_keys($existingCodes)));

        $zonesCreated = 0;
        if ($this->commit) {
            $zoneMap = $this->ensureZones($location->id, $allRaks, $zonesCreated);
            foreach ($newCodes as $code) {
                $struct = $this->deriveStructure($code);
                $zoneCode = $struct['floor_code']; // segmen pertama = zona
                $bin = LocationBin::firstOrCreate(
                    ['location_id' => $location->id, 'bin_final_code' => $code],
                    array_merge($struct, [
                        'zone_id' => $zoneMap[$zoneCode] ?? null,
                        'is_inbound' => false,
                        'is_stock_acknowledged' => true,
                        'is_large_bin' => false,
                        'category' => null,
                    ])
                );
                $codeToBinId[$code] = $bin->id;
            }
        }

        // ---- Phase E: ALOKASI (rak 1-SKU) --------------------------------------
        $alloc = ['moved' => 0, 'no_stock' => 0, 'conflict' => 0, 'already' => 0, 'skipped_missing' => 0, 'qty' => 0];

        foreach ($singleSkuRaks as $rak => $sku) {
            $itemId = $variantMap[$sku] ?? null;
            if (! $itemId) { $alloc['skipped_missing']++; continue; }

            $targetBinId = $codeToBinId[$rak] ?? null; // null di dry-run utk kode baru

            // konflik: rak tujuan sudah dihuni SKU LAIN
            if ($targetBinId) {
                $occupant = Inventory::where('bin_id', $targetBinId)
                    ->where(fn ($w) => $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0))
                    ->where('item_id', '!=', $itemId)
                    ->value('item_id');
                if ($occupant !== null) {
                    $alloc['conflict']++;
                    $this->reports['rak_tujuan_konflik'][] = ['rak' => $rak, 'sku' => $sku, 'dihuni_item_id' => $occupant];
                    continue;
                }
            }

            // stok yang perlu dipindah = total on_hand SKU ini di lokasi - yang sudah di target
            $totalOnHand = (int) Inventory::where('item_id', $itemId)
                ->where('location_id', $location->id)
                ->where('on_hand', '>', 0)
                ->sum('on_hand');
            $inTarget = $targetBinId ? (int) Inventory::where('item_id', $itemId)
                ->where('location_id', $location->id)
                ->where('bin_id', $targetBinId)
                ->sum('on_hand') : 0;
            $sourceOnHand = $totalOnHand - $inTarget;

            if ($sourceOnHand <= 0) {
                $alloc['no_stock']++; // kode rak dibuat, tak ada stok utk dipindah
                continue;
            }

            if (! $this->commit || ! $targetBinId) {
                $alloc['moved']++; $alloc['qty'] += $sourceOnHand; // prediksi
                continue;
            }

            $moved = $this->relocateStock($inventoryRepo, $movementRepo, $itemId, $location->id, $targetBinId);
            if ($moved > 0) { $alloc['moved']++; $alloc['qty'] += $moved; }
            else { $alloc['already']++; }
        }

        // ---- Phase F: SKU cilupbah ber-stok yang TAK ada di file ---------------
        $fileSkuIds = array_values(array_filter(array_map(fn ($s) => $variantMap[$s] ?? null, $allSkus)));
        $unmatched = Inventory::query()
            ->where('location_id', $location->id)
            ->where('on_hand', '>', 0)
            ->whereHas('bin', fn ($q) => $q->where('is_inbound', false)->where('is_stock_acknowledged', true))
            ->when(! empty($fileSkuIds), fn ($q) => $q->whereNotIn('item_id', $fileSkuIds))
            ->with('product:id,sku')
            ->get()
            ->groupBy('item_id');
        foreach ($unmatched as $itemId => $invs) {
            $this->reports['sku_bergudang_tak_di_file'][] = [
                'item_id' => $itemId,
                'sku' => optional($invs->first()->product)->sku ?? '-',
                'on_hand' => (int) $invs->sum('on_hand'),
            ];
        }

        // ---- Ringkasan ----------------------------------------------------------
        $this->printSummary($rows, $allRaks, $singleSkuRaks, $multiSkuRaks, $newCodes, $zonesCreated, $alloc);
        $this->dumpReports();

        if (! $this->commit) {
            $this->line('');
            $this->warn('DRY-RUN selesai. Tidak ada perubahan yang ditulis. Jalankan ulang dengan --commit untuk menerapkan.');
            if (! empty($this->reports['sku_bergudang_tak_di_file'])) {
                $this->warn('PERHATIAN: ada SKU ber-stok di gudang yang TIDAK tercantum di file (lihat laporan sku_bergudang_tak_di_file).');
            }
        } else {
            $this->info('COMMIT selesai. Perubahan telah ditulis ke DB.');
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------------

    /**
     * Pindahkan SELURUH on_hand sebuah SKU (dari semua bin selain target) ke rak target.
     * Otoritatif (bypass guard interaktif) — kebenaran invarian dijamin di pemanggil
     * (hanya dipakai untuk rak 1-SKU). Menulis ledger PUTAWAY_OUT/IN agar auditable.
     */
    private function relocateStock(
        InventoryRepository $inventoryRepo,
        InventoryMovementRepository $movementRepo,
        string $itemId,
        string $locationId,
        string $targetBinId
    ): int {
        return DB::transaction(function () use ($inventoryRepo, $movementRepo, $itemId, $locationId, $targetBinId) {
            $txn = 'RLY-'.Str::upper(Str::random(8));

            $sources = Inventory::where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->where('on_hand', '>', 0)
                ->lockForUpdate()
                ->get();

            $moved = 0;
            foreach ($sources as $src) {
                if ($src->bin_id === $targetBinId) { continue; } // sudah di target
                $qty = (int) $src->on_hand;
                if ($qty <= 0) { continue; }

                $src->on_hand -= $qty;
                $inventoryRepo->updateStock($src);
                $movementRepo->create([
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'bin_id' => $src->bin_id,
                    'transaction_number' => $txn,
                    'source' => 'PUTAWAY_OUT',
                    'qty' => -$qty,
                    'balance' => $src->on_hand,
                    'transaction_date' => now(),
                    'created_by' => self::CREATED_BY,
                ]);
                $moved += $qty;
            }

            if ($moved > 0) {
                $dest = $inventoryRepo->findOrCreateForUpdate($itemId, $locationId, $targetBinId);
                $dest->on_hand += $moved;
                $inventoryRepo->updateStock($dest);
                $movementRepo->create([
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'bin_id' => $targetBinId,
                    'transaction_number' => $txn,
                    'source' => 'PUTAWAY_IN',
                    'qty' => $moved,
                    'balance' => $dest->on_hand,
                    'transaction_date' => now(),
                    'created_by' => self::CREATED_BY,
                ]);
            }

            return $moved;
        });
    }

    /** Buat LocationZone dari segmen pertama tiap kode rak (unik per lokasi). */
    private function ensureZones(string $locationId, array $allRaks, int &$created): array
    {
        $zoneCodes = [];
        foreach ($allRaks as $rak) {
            $zoneCodes[$this->deriveStructure($rak)['floor_code']] = true;
        }
        $map = LocationZone::where('location_id', $locationId)->pluck('id', 'zone_code')->all();
        foreach (array_keys($zoneCodes) as $code) {
            if ($code === '' || isset($map[$code])) { continue; }
            $zone = LocationZone::firstOrCreate(
                ['location_id' => $locationId, 'zone_code' => $code],
                ['zone_name' => null]
            );
            if ($zone->wasRecentlyCreated) { $created++; }
            $map[$code] = $zone->id;
        }
        return $map;
    }

    /** Split kode rak by '-' → floor(zona)/row/column/bin (best-effort, sisa digabung ke bin). */
    private function deriveStructure(string $code): array
    {
        $parts = explode('-', $code);
        $floor = $parts[0] ?? null;
        $row = $parts[1] ?? null;
        $col = $parts[2] ?? null;
        $bin = isset($parts[3]) ? implode('-', array_slice($parts, 3)) : null;

        return [
            'floor_code' => $floor !== '' ? $floor : null,
            'row_code' => $row,
            'column_code' => $col,
            'bin_code' => $bin,
        ];
    }

    /** Rak dengan segmen box non-numerik (KANTOR/OUTBOUND/REFUND/ADJST/...) = rak spesial. */
    private function isSpecialRak(string $code): bool
    {
        return (bool) ! preg_match('/-[A-Za-z]*[0-9]+$/', $code);
    }

    /**
     * @return array{0: array<int,array{sku:string,lokasi:string,rak:string}>, 1: array<int,array<string,string>>}
     */
    private function parseCsv(string $path, string $locationName): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) { return [[], []]; }

        $header = fgetcsv($fh);
        if ($header === false) { fclose($fh); return [[], []]; }

        $idx = $this->resolveColumns($header);
        if ($idx['sku'] === null || $idx['rak'] === null) {
            fclose($fh);
            $this->error('Header CSV tidak dikenali. Butuh minimal kolom SKU dan Rak.');
            return [[], []];
        }

        $rows = [];
        $otherLoc = [];
        $needle = mb_strtolower(trim($locationName));
        while (($data = fgetcsv($fh)) !== false) {
            $sku = trim((string) ($data[$idx['sku']] ?? ''));
            $rak = trim((string) ($data[$idx['rak']] ?? ''));
            $lok = $idx['lokasi'] !== null ? trim((string) ($data[$idx['lokasi']] ?? '')) : $locationName;
            if ($sku === '' || $rak === '') { continue; }

            if ($idx['lokasi'] !== null && mb_strtolower($lok) !== $needle) {
                $otherLoc[] = ['sku' => $sku, 'lokasi' => $lok, 'rak' => $rak];
                continue;
            }
            $rows[] = ['sku' => $sku, 'lokasi' => $lok, 'rak' => $rak];
        }
        fclose($fh);

        return [$rows, $otherLoc];
    }

    /** @return array{sku:?int,lokasi:?int,rak:?int} */
    private function resolveColumns(array $header): array
    {
        $norm = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);
        $find = function (array $aliases) use ($norm) {
            foreach ($norm as $i => $name) {
                if (in_array($name, $aliases, true)) { return $i; }
            }
            return null;
        };
        return [
            'sku' => $find(['sku', 'kode_sku']),
            'lokasi' => $find(['lokasi', 'location', 'gudang']),
            'rak' => $find(['rak', 'kode_rak', 'bin', 'kode rak']),
        ];
    }

    private function printSummary(
        array $rows,
        array $allRaks,
        array $singleSkuRaks,
        array $multiSkuRaks,
        array $newCodes,
        int $zonesCreated,
        array $alloc
    ): void {
        $this->line('');
        $this->line('<options=bold>== RINGKASAN ==</>');
        $this->table(['Metrik', 'Nilai'], [
            ['Baris valid (lokasi ini)', number_format(count($rows))],
            ['Rak unik total', number_format(count($allRaks))],
            ['  - kode rak BARU dibuat', number_format(count($newCodes)).($this->commit ? '' : ' (dry-run: belum dibuat)')],
            ['  - kode rak sudah ada (skip)', number_format(count($allRaks) - count($newCodes))],
            ['Zona dibuat', $this->commit ? number_format($zonesCreated) : '(dry-run)'],
            ['Rak 1-SKU (dialokasi)', number_format(count($singleSkuRaks))],
            ['Rak multi-SKU (TIDAK dialokasi)', number_format(count($multiSkuRaks))],
            ['  → stok dipindah (SKU)', number_format($alloc['moved']).($this->commit ? '' : ' (prediksi)')],
            ['  → qty dipindah', number_format($alloc['qty'])],
            ['  → sudah di rak tujuan', number_format($alloc['already'])],
            ['  → kode dibuat, stok 0', number_format($alloc['no_stock'])],
            ['  → rak tujuan konflik SKU lain', number_format($alloc['conflict'])],
            ['  → SKU tak ada di produk', number_format($alloc['skipped_missing'])],
        ]);
    }

    private function dumpReports(): void
    {
        if (empty($this->reports)) { return; }

        $dir = (string) ($this->option('report-dir') ?: storage_path('app/bin-migration'));
        if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
        $stamp = now()->format('Ymd_His');

        $this->line('');
        $this->line('<options=bold>== LAPORAN ==</>');
        foreach ($this->reports as $name => $rows) {
            $count = count($rows);
            $path = "{$dir}/{$name}_{$stamp}.csv";
            $this->line(sprintf('  %-28s %6d baris  → %s', $name, $count, $path));

            $fh = @fopen($path, 'w');
            if ($fh === false) { continue; }
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $r) { fputcsv($fh, $r); }
            fclose($fh);
        }
    }
}
