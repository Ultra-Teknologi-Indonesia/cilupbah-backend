<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

class ImportBaselineStock extends Command
{
    protected $signature = 'inventory:import-baseline
        {file : Path file Excel (ekspor Jubelio atau template impor penyesuaian stok)}
        {--location= : Kode lokasi tujuan, contoh WH-KECIL atau WH-PUSAT}
        {--commit : Terapkan perubahan ke database. Default: DRY-RUN simulasi}
        {--zero-missing : Nolkan stok SKU x Rak yang ada di sistem tetapi tidak ada di file}
        {--chunk=1000 : Ukuran batch transaksi database}
        {--export= : Custom path untuk file output CSV laporan}
        {--limit=15 : Jumlah contoh yang ditampilkan per jenis masalah}';

    protected $description = 'Validasi dan eksekusi impor stok baseline Jubelio ke cilupbah dengan laporan lengkap & downloadable URL.';

    private const TEMPLATE_SHEET = 'Pengisian Data';

    public function __construct(
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $location = $this->resolveLocation();

        if (! $location) {
            return self::FAILURE;
        }

        $isCommit = (bool) $this->option('commit');
        $zeroMissing = (bool) $this->option('zero-missing');
        $chunkSize = max(100, (int) $this->option('chunk'));
        $modeStr = $isCommit ? '<fg=red;options=bold>COMMIT (MENULIS KE DB)</>' : '<fg=yellow;options=bold>DRY-RUN (SIMULASI AMAN)</>';

        $this->line('===============================================================');
        $this->line("  IMPOR BASELINE STOK JUBELIO — CILUPBAH SUPERAPP");
        $this->line('===============================================================');
        $this->line("Mode          : {$modeStr}");
        $this->line("Lokasi Tujuan : {$location->location_name} ({$location->location_code})");
        $this->line("File Sumber   : {$path}");
        $this->line("Zero Missing  : " . ($zeroMissing ? 'AKTIF (stok lama yang tidak ada di file akan dinolkan)' : 'NON-AKTIF'));
        $this->newLine();

        $rows = $this->readRows($path);

        if ($rows === []) {
            $this->error('Tidak ada baris berisi stok yang bisa dibaca dari file ini.');

            return self::FAILURE;
        }

        $this->line(sprintf('Total baris ber-stok terbaca: %s', number_format(count($rows))));
        $this->newLine();

        $inspection = $this->inspect($rows, $location->id);

        $this->renderSummary($inspection, (int) $this->option('limit'));

        $zeroedItems = [];
        if ($isCommit) {
            $this->newLine();
            $this->info("Memulai eksekusi penulisan stok ke database...");

            $executionResult = $this->executeCommit(
                $inspection['valid_rows'],
                $location,
                basename($path),
                $chunkSize,
                $zeroMissing,
            );

            $zeroedItems = $executionResult['zeroed_items'] ?? [];
            $this->info("Eksekusi database selesai!");
            $this->line(sprintf("  · Penyesuaian Dibuat : %s item", number_format($executionResult['applied_count'])));
            $this->line(sprintf("  · Dokumen Baseline   : %s", $executionResult['adjustment_no']));
            if ($zeroMissing) {
                $this->line(sprintf("  · Stok Dinolkan      : %s item", number_format(count($zeroedItems))));
            }
        }

        $reportInfo = $this->generateReport(
            $inspection,
            $location,
            $isCommit,
            $zeroedItems,
            $this->option('export') ? (string) $this->option('export') : null,
        );

        $this->newLine();
        $this->line('===============================================================');
        $this->info("LAPORAN LENGKAP TELAH DIBUAT");
        $this->line("File Path : {$reportInfo['file_path']}");
        $this->line("Download  : <fg=cyan;options=bold>{$reportInfo['download_url']}</>");
        $this->line('===============================================================');

        if (! $isCommit && $inspection['blocking'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                'Terdapat %s baris bermasalah yang akan ditolak saat commit. Periksa download URL di atas untuk daftar lengkap.',
                number_format($inspection['blocking'])
            ));
        }

        return self::SUCCESS;
    }

    private function resolveLocation(): ?object
    {
        $code = trim((string) $this->option('location'));

        if ($code === '') {
            $this->error('Opsi --location wajib diisi. Contoh: --location=WH-KECIL atau --location=WH-PUSAT');
            $this->listLocations();

            return null;
        }

        $location = DB::table('locations')
            ->where('location_code', $code)
            ->first(['id', 'location_code', 'location_name']);

        if (! $location) {
            $this->error("Lokasi dengan kode '{$code}' tidak ditemukan.");
            $this->listLocations();

            return null;
        }

        return $location;
    }

    private function listLocations(): void
    {
        $rows = DB::table('locations')
            ->orderBy('location_code')
            ->get(['location_code', 'location_name'])
            ->map(fn ($l) => [$l->location_code, $l->location_name])
            ->all();

        $this->newLine();
        $this->table(['Kode', 'Nama Lokasi'], $rows);
    }

    private function readRows(string $path): array
    {
        @ini_set('memory_limit', '1024M');

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            return $this->readCsvRows($path);
        }

        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        $sheetNames = method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [];
        $isTemplate = in_array(self::TEMPLATE_SHEET, $sheetNames, true);

        if ($isTemplate) {
            $reader->setLoadSheetsOnly(self::TEMPLATE_SHEET);
        }

        $columns = $isTemplate ? ['A', 'B', 'C', 'D'] : ['A', 'D', 'H', 'I'];
        $lastColumn = $isTemplate ? 'D' : 'I';
        $totalRows = $this->countRows($reader, $path, $isTemplate);

        $rows = [];
        $chunkSize = 2500;
        $hardCap = 2000000;
        $start = 2;

        while ($start <= $hardCap) {
            $end = $totalRows > 0 ? min($start + $chunkSize - 1, $totalRows) : $start + $chunkSize - 1;

            if ($totalRows > 0 && $start > $totalRows) {
                break;
            }

            $reader->setReadFilter($this->chunkFilter($columns, $start, $end));
            $spreadsheet = $reader->load($path);

            $slice = $spreadsheet->getActiveSheet()
                ->rangeToArray("A{$start}:{$lastColumn}{$end}", null, true, false, false);

            $seen = 0;

            foreach ($slice as $offset => $raw) {
                $rowNo = $start + $offset;

                if (array_filter($raw, fn ($v) => $v !== null && $v !== '') !== []) {
                    $seen++;
                }

                if ($isTemplate) {
                    $sku = trim((string) ($raw[0] ?? ''));
                    $bin = trim((string) ($raw[1] ?? ''));
                    $qty = (int) ($raw[3] ?? 0);
                    $fileLocation = null;
                } else {
                    $sku = trim((string) ($raw[0] ?? ''));
                    $fileLocation = trim((string) ($raw[3] ?? '')) ?: null;
                    $bin = trim((string) ($raw[7] ?? ''));
                    $qty = isset($raw[9]) && $raw[9] !== '' && $raw[9] !== null
                        ? (int) $raw[9]
                        : (int) ($raw[8] ?? 0);
                }

                if ($sku === '' || $qty <= 0) {
                    continue;
                }

                $rows[] = [
                    'row' => $rowNo,
                    'sku' => $sku,
                    'bin' => $bin,
                    'qty' => $qty,
                    'file_location' => $fileLocation,
                ];
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $slice);

            $this->line(sprintf(
                '  dibaca baris %s–%s · %s baris ber-stok terkumpul',
                number_format($start),
                number_format($end),
                number_format(count($rows)),
            ));

            if ($seen === 0) {
                break;
            }

            $start = $end + 1;
        }

        $this->newLine();

        return $rows;
    }

    private function readCsvRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }

        $headerMap = [];
        foreach ($header as $idx => $colName) {
            $headerMap[strtolower(trim((string) $colName))] = $idx;
        }

        $isTemplate = isset($headerMap['sku']) && isset($headerMap['kode rak']) && isset($headerMap['qty']);
        $rows = [];
        $rowNo = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $rowNo++;
            if ($isTemplate) {
                $sku = trim((string) ($raw[$headerMap['sku'] ?? 0] ?? ''));
                $bin = trim((string) ($raw[$headerMap['kode rak'] ?? 1] ?? ''));
                $qty = (int) ($raw[$headerMap['qty'] ?? 3] ?? 0);
                $fileLocation = null;
            } else {
                $sku = trim((string) ($raw[$headerMap['sku'] ?? 0] ?? ''));
                $fileLocation = trim((string) ($raw[$headerMap['lokasi'] ?? 3] ?? '')) ?: null;
                $bin = trim((string) ($raw[$headerMap['no rak'] ?? 7] ?? ''));
                $actualIdx = $headerMap['qty aktual'] ?? $headerMap['qty actual'] ?? null;
                $onHandIdx = $headerMap['qty on hand'] ?? 8;
                $qty = $actualIdx !== null && isset($raw[$actualIdx]) && $raw[$actualIdx] !== ''
                    ? (int) $raw[$actualIdx]
                    : (int) ($raw[$onHandIdx] ?? 0);
            }

            if ($sku === '' || $qty <= 0) {
                continue;
            }

            $rows[] = [
                'row' => $rowNo,
                'sku' => $sku,
                'bin' => $bin,
                'qty' => $qty,
                'file_location' => $fileLocation,
            ];
        }

        fclose($handle);

        $this->line(sprintf('  dibaca %s baris ber-stok dari CSV', number_format(count($rows))));
        $this->newLine();

        return $rows;
    }

    private function countRows(XlsxReader $reader, string $path, bool $isTemplate): int
    {
        if (! method_exists($reader, 'listWorksheetInfo')) {
            return 0;
        }

        foreach ($reader->listWorksheetInfo($path) as $info) {
            if (! $isTemplate || $info['worksheetName'] === self::TEMPLATE_SHEET) {
                return (int) ($info['totalRows'] ?? 0);
            }
        }

        return 0;
    }

    private function chunkFilter(array $columns, int $startRow, int $endRow): IReadFilter
    {
        return new class($columns, $startRow, $endRow) implements IReadFilter
        {
            public function __construct(
                private array $columns,
                private int $startRow,
                private int $endRow,
            ) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->startRow
                    && $row <= $this->endRow
                    && in_array($columnAddress, $this->columns, true);
            }
        };
    }

    private function inspect(array $rows, string $locationId): array
    {
        $skus = array_values(array_unique(array_column($rows, 'sku')));
        $bins = array_values(array_filter(array_unique(array_column($rows, 'bin'))));

        $variants = $this->lookupVariants($skus);
        $binsHere = $this->lookupBins($bins, $locationId);
        $binsElsewhere = $this->lookupBinsElsewhere($bins, $locationId, array_keys($binsHere));

        $defaultBinId = DB::table('location_bins')
            ->where('location_id', $locationId)
            ->where('is_inbound', true)
            ->value('id');

        $problems = [
            'sku_hilang' => [],
            'sku_beda_huruf' => [],
            'sku_ganda' => [],
            'sku_nonaktif' => [],
            'rak_hilang' => [],
            'rak_gudang_lain' => [],
            'rak_kosong' => [],
        ];

        $lowerIndex = [];
        foreach ($variants as $sku => $variant) {
            $lowerIndex[mb_strtolower($sku)][] = $sku;
        }

        $variantIds = array_filter(array_column(array_values($variants), 'id'));
        $currentStockMap = [];
        if (! empty($variantIds)) {
            foreach (array_chunk($variantIds, 2000) as $chunk) {
                $invRows = DB::table('inventories')
                    ->where('location_id', $locationId)
                    ->whereIn('item_id', $chunk)
                    ->get(['item_id', 'bin_id', 'on_hand']);
                foreach ($invRows as $inv) {
                    $key = $inv->item_id . ':' . ($inv->bin_id ?? 'null');
                    $currentStockMap[$key] = (float) $inv->on_hand;
                }
            }
        }

        $validRows = [];
        $allEvaluatedRows = [];
        $okRows = 0;
        $okQty = 0;
        $lostQty = 0;
        $blockedRows = 0;

        foreach ($rows as $row) {
            $sku = $row['sku'];
            $bin = $row['bin'];
            $blocked = false;
            $status = 'VALID';
            $notes = 'Siap diimpor';

            if (! isset($variants[$sku])) {
                $alternatives = $lowerIndex[mb_strtolower($sku)] ?? [];

                if ($alternatives !== []) {
                    $notes = 'SKU beda huruf besar/kecil (di sistem: ' . implode(', ', $alternatives) . ')';
                    $problems['sku_beda_huruf'][] = $row + ['catatan' => $notes];
                    $status = 'DITOLAK_SKU_CASE';
                } else {
                    $notes = 'SKU tidak terdaftar di master produk';
                    $problems['sku_hilang'][] = $row + ['catatan' => $notes];
                    $status = 'DITOLAK_SKU_HILANG';
                }

                $blocked = true;
            } else {
                $variant = $variants[$sku];

                if ($variant->jumlah > 1) {
                    $problems['sku_ganda'][] = $row + ['catatan' => "{$variant->jumlah} varian memakai SKU ini"];
                }

                if ($variant->is_active === false) {
                    $problems['sku_nonaktif'][] = $row + ['catatan' => 'varian berstatus non-aktif'];
                }
            }

            $resolvedBinId = null;

            if ($bin === '') {
                $problems['rak_kosong'][] = $row + ['catatan' => 'kode rak kosong, dialokasikan ke rak default/inbound'];
                $resolvedBinId = $defaultBinId;
            } elseif (! isset($binsHere[$bin])) {
                if (isset($binsElsewhere[$bin])) {
                    $notes = 'Kode rak milik gudang lain: ' . $binsElsewhere[$bin];
                    $problems['rak_gudang_lain'][] = $row + ['catatan' => $notes];
                    $status = 'DITOLAK_RAK_GUDANG_LAIN';
                } else {
                    $notes = 'Kode rak belum ada di sistem';
                    $problems['rak_hilang'][] = $row + ['catatan' => $notes];
                    $status = 'DITOLAK_RAK_HILANG';
                }

                $blocked = true;
            } else {
                $resolvedBinId = $binsHere[$bin]->id;
            }

            $variantId = isset($variants[$sku]) ? $variants[$sku]->id : null;
            $pairKey = $variantId . ':' . ($resolvedBinId ?? 'null');
            $curOnHand = (float) ($currentStockMap[$pairKey] ?? 0.0);
            $targetOnHand = (float) $row['qty'];
            $delta = $targetOnHand - $curOnHand;

            $evaluatedRow = $row + [
                'status' => $status,
                'catatan' => $notes,
                'variant_id' => $variantId,
                'bin_id' => $resolvedBinId,
                'current_on_hand' => $curOnHand,
                'target_on_hand' => $targetOnHand,
                'delta' => $delta,
            ];

            $allEvaluatedRows[] = $evaluatedRow;

            if ($blocked) {
                $blockedRows++;
                $lostQty += $row['qty'];
            } else {
                $okRows++;
                $okQty += $row['qty'];
                $validRows[] = $evaluatedRow;
            }
        }

        return [
            'total_rows' => count($rows),
            'total_qty' => array_sum(array_column($rows, 'qty')),
            'ok_rows' => $okRows,
            'ok_qty' => $okQty,
            'lost_qty' => $lostQty,
            'blocking' => $blockedRows,
            'problems' => $problems,
            'valid_rows' => $validRows,
            'all_rows' => $allEvaluatedRows,
        ];
    }

    private function lookupVariants(array $skus): array
    {
        $found = [];

        foreach (array_chunk($skus, 2000) as $chunk) {
            $rows = DB::table('product_variants')
                ->whereIn('sku', $chunk)
                ->get([
                    'id',
                    'sku',
                    'product_id',
                    'is_active',
                ]);

            foreach ($rows as $row) {
                if (! isset($found[$row->sku])) {
                    $found[$row->sku] = (object) [
                        'id' => $row->id,
                        'sku' => $row->sku,
                        'product_id' => $row->product_id,
                        'jumlah' => 1,
                        'is_active' => (bool) ($row->is_active ?? true),
                    ];
                } else {
                    $found[$row->sku]->jumlah++;
                }
            }
        }

        return $found;
    }

    private function lookupBins(array $bins, string $locationId): array
    {
        $found = [];

        foreach (array_chunk($bins, 2000) as $chunk) {
            $rows = DB::table('location_bins')
                ->where('location_id', $locationId)
                ->whereIn('bin_final_code', $chunk)
                ->get(['id', 'bin_final_code']);

            foreach ($rows as $row) {
                $found[$row->bin_final_code] = $row;
            }
        }

        return $found;
    }

    private function lookupBinsElsewhere(array $bins, string $locationId, array $alreadyFound): array
    {
        $candidates = array_values(array_diff($bins, $alreadyFound));
        $found = [];

        foreach (array_chunk($candidates, 2000) as $chunk) {
            $rows = DB::table('location_bins')
                ->join('locations', 'locations.id', '=', 'location_bins.location_id')
                ->where('location_bins.location_id', '!=', $locationId)
                ->whereIn('location_bins.bin_final_code', $chunk)
                ->get(['location_bins.bin_final_code', 'locations.location_code']);

            foreach ($rows as $row) {
                $found[$row->bin_final_code] = $row->location_code;
            }
        }

        return $found;
    }

    private function renderSummary(array $report, int $limit): void
    {
        $this->table(['Ringkasan', 'Nilai'], [
            ['Total Baris di File', number_format($report['total_rows'])],
            ['Total Qty di File', number_format($report['total_qty']) . ' pcs'],
            ['Baris Valid (Lolos)', number_format($report['ok_rows'])],
            ['Qty yang Siap Masuk', number_format($report['ok_qty']) . ' pcs'],
            ['Baris Bermasalah / Ditolak', number_format($report['blocking'])],
            ['Qty yang Ditolak', number_format($report['lost_qty']) . ' pcs'],
        ]);

        if (! empty($report['valid_rows'])) {
            $previewCount = min($limit, count($report['valid_rows']));
            $this->newLine();
            $this->line(sprintf('PREVIEW ITEM LOLOS / VALID (Menampilkan %d dari %s baris siap masuk):', $previewCount, number_format($report['ok_rows'])));

            $this->table(
                ['Baris', 'SKU', 'Rak', 'Stok Saat Ini (on_hand)', 'Stok Baru (Aktual)', 'Perubahan (Delta)', 'Status'],
                collect($report['valid_rows'])->take($limit)->map(fn ($i) => [
                    $i['row'],
                    $i['sku'],
                    $i['bin'] ?: '(Rak Default/Inbound)',
                    number_format($i['current_on_hand']) . ' pcs',
                    number_format($i['target_on_hand']) . ' pcs',
                    ($i['delta'] > 0 ? '+' : '') . number_format($i['delta']) . ' pcs',
                    '<fg=green;options=bold>VALID (SIAP)</>',
                ])->all(),
            );

            if (count($report['valid_rows']) > $limit) {
                $this->line(sprintf('  … %s baris valid lainnya tidak ditampilkan di layar CLI. Semua baris lengkap ada di file CSV laporan.', number_format(count($report['valid_rows']) - $limit)));
            }
        }

        $labels = [
            'sku_hilang' => 'SKU tidak terdaftar (baris DITOLAK)',
            'sku_beda_huruf' => 'SKU beda huruf besar/kecil (baris DITOLAK)',
            'rak_hilang' => 'Kode rak belum ada di sistem (baris DITOLAK)',
            'rak_gudang_lain' => 'Kode rak milik gudang lain (baris DITOLAK)',
            'sku_ganda' => 'SKU dipakai lebih dari satu varian (peringatan)',
            'sku_nonaktif' => 'Varian non-aktif (peringatan)',
            'rak_kosong' => 'Kode rak kosong (peringatan)',
        ];

        foreach ($labels as $key => $label) {
            $items = $report['problems'][$key];

            if ($items === []) {
                continue;
            }

            $this->newLine();
            $this->line(sprintf('%s — %s baris, %s pcs', $label, number_format(count($items)), number_format(array_sum(array_column($items, 'qty')))));

            $this->table(
                ['Baris', 'SKU', 'Rak', 'Qty', 'Catatan'],
                collect($items)->take($limit)->map(fn ($i) => [
                    $i['row'], $i['sku'], $i['bin'] ?: '—', $i['qty'], $i['catatan'],
                ])->all(),
            );

            if (count($items) > $limit) {
                $this->line(sprintf('  … %s baris lain tidak ditampilkan. Semua baris tercantum di file laporan CSV.', number_format(count($items) - $limit)));
            }
        }
    }

    private function executeCommit(
        array $validRows,
        object $location,
        string $sourceFilename,
        int $chunkSize,
        bool $zeroMissing,
    ): array {
        $timestamp = date('YmdHis');
        $adjustmentNo = 'ADJ-BASELINE-' . $location->location_code . '-' . $timestamp;

        $adjustment = StockAdjustment::create([
            'adjustment_no' => $adjustmentNo,
            'transaction_date' => now(),
            'location_id' => $location->id,
            'is_beginning_balance' => true,
            'notes' => 'Import Baseline Stok Jubelio ' . $sourceFilename,
            'created_by' => 'baseline-migrator',
        ]);

        $appliedCount = 0;
        $seenPairs = [];

        foreach (array_chunk($validRows, $chunkSize) as $chunk) {
            DB::transaction(function () use ($chunk, $adjustment, $location, &$appliedCount, &$seenPairs) {
                foreach ($chunk as $row) {
                    $itemId = $row['variant_id'];
                    $binId = $row['bin_id'];
                    $actualQty = (float) $row['qty'];

                    $pairKey = $itemId . ':' . ($binId ?? 'null');
                    $seenPairs[$pairKey] = true;

                    $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                        $itemId,
                        $location->id,
                        $binId,
                    );

                    $systemQty = (float) $inventory->on_hand;
                    $diff = $actualQty - $systemQty;

                    if ($diff != 0.0) {
                        $inventory->on_hand = $actualQty;
                        $this->inventoryRepository->updateStock($inventory);

                        StockAdjustmentItem::create([
                            'stock_adjustment_id' => $adjustment->id,
                            'item_id' => $itemId,
                            'bin_id' => $binId,
                            'system_qty' => $systemQty,
                            'actual_qty' => $actualQty,
                            'difference_qty' => $diff,
                            'unit_cost' => (float) ($inventory->avg_cost ?? 0),
                            'notes' => 'Baseline snapshot',
                        ]);

                        $this->movementRepository->create([
                            'item_id' => $itemId,
                            'location_id' => $location->id,
                            'bin_id' => $binId,
                            'transaction_number' => $adjustment->adjustment_no,
                            'source' => 'ADJUSTMENT',
                            'qty' => $diff,
                            'balance' => $actualQty,
                            'cost_per_unit' => $inventory->avg_cost > 0 ? $inventory->avg_cost : null,
                            'total_cost' => $inventory->avg_cost > 0 ? round($diff * (float) $inventory->avg_cost, 2) : null,
                            'transaction_date' => now(),
                            'created_by' => 'baseline-migrator',
                        ]);

                        $appliedCount++;
                    }
                }
            });
        }

        $zeroedItems = [];
        if ($zeroMissing) {
            $existingInventories = DB::table('inventories')
                ->where('location_id', $location->id)
                ->where('on_hand', '>', 0)
                ->get(['id', 'item_id', 'bin_id', 'on_hand', 'avg_cost']);

            foreach ($existingInventories as $inv) {
                $pairKey = $inv->item_id . ':' . ($inv->bin_id ?? 'null');
                if (! isset($seenPairs[$pairKey])) {
                    $systemQty = (float) $inv->on_hand;
                    $diff = -$systemQty;

                    DB::transaction(function () use ($inv, $adjustment, $location, $systemQty, $diff) {
                        $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                            $inv->item_id,
                            $location->id,
                            $inv->bin_id,
                        );

                        $inventory->on_hand = 0;
                        $this->inventoryRepository->updateStock($inventory);

                        StockAdjustmentItem::create([
                            'stock_adjustment_id' => $adjustment->id,
                            'item_id' => $inv->item_id,
                            'bin_id' => $inv->bin_id,
                            'system_qty' => $systemQty,
                            'actual_qty' => 0,
                            'difference_qty' => $diff,
                            'unit_cost' => (float) ($inv->avg_cost ?? 0),
                            'notes' => 'Zero-missing: dinolkan karena tidak tercantum di file Jubelio',
                        ]);

                        $this->movementRepository->create([
                            'item_id' => $inv->item_id,
                            'location_id' => $location->id,
                            'bin_id' => $inv->bin_id,
                            'transaction_number' => $adjustment->adjustment_no,
                            'source' => 'ADJUSTMENT',
                            'qty' => $diff,
                            'balance' => 0,
                            'cost_per_unit' => $inv->avg_cost > 0 ? (float) $inv->avg_cost : null,
                            'total_cost' => $inv->avg_cost > 0 ? round($diff * (float) $inv->avg_cost, 2) : null,
                            'transaction_date' => now(),
                            'created_by' => 'baseline-migrator',
                        ]);
                    });

                    $zeroedItems[] = [
                        'item_id' => $inv->item_id,
                        'bin_id' => $inv->bin_id,
                        'qty_sebelumnya' => $systemQty,
                    ];
                }
            }
        }

        return [
            'adjustment_no' => $adjustmentNo,
            'applied_count' => $appliedCount,
            'zeroed_items' => $zeroedItems,
        ];
    }

    private function generateReport(
        array $inspection,
        object $location,
        bool $isCommit,
        array $zeroedItems,
        ?string $customExportPath,
    ): array {
        $timestamp = date('Ymd_His');
        $mode = $isCommit ? 'COMMIT' : 'DRYRUN';
        $filename = "baseline_report_{$location->location_code}_{$timestamp}_{$mode}.csv";

        $storageRelDir = 'baseline-reports';
        Storage::disk('public')->makeDirectory($storageRelDir);
        $storagePath = Storage::disk('public')->path("{$storageRelDir}/{$filename}");

        $targetPath = $customExportPath ?: $storagePath;

        $handle = fopen($targetPath, 'w');

        fputcsv($handle, [
            'no_baris',
            'sku',
            'kode_rak',
            'stok_saat_ini_on_hand',
            'stok_baru_aktual',
            'selisih_delta',
            'status',
            'keterangan_alasan',
        ]);

        foreach ($inspection['all_rows'] as $row) {
            fputcsv($handle, [
                $row['row'],
                $row['sku'],
                $row['bin'] ?: '(inbound/default)',
                $row['current_on_hand'] ?? 0,
                $row['target_on_hand'] ?? $row['qty'],
                $row['delta'] ?? $row['qty'],
                $row['status'],
                $row['catatan'],
            ]);
        }

        if ($zeroedItems !== []) {
            foreach ($zeroedItems as $z) {
                fputcsv($handle, [
                    '—',
                    'ITEM_ID: ' . $z['item_id'],
                    'BIN_ID: ' . ($z['bin_id'] ?? 'default'),
                    '0',
                    'ZEROED_MISSING',
                    'Dinolkan dari sisa stok lama ' . $z['qty_sebelumnya'] . ' pcs',
                ]);
            }
        }

        fclose($handle);

        $appUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $downloadUrl = "{$appUrl}/storage/{$storageRelDir}/{$filename}";

        return [
            'file_path' => $targetPath,
            'download_url' => $downloadUrl,
        ];
    }
}
