<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Warehouse\Services\BinMultiSkuRuleService;

class ImportBaselineStock extends Command
{
    protected $signature = 'inventory:import-baseline
        {file : Path file Excel (ekspor Jubelio atau template impor penyesuaian stok)}
        {--location= : Kode lokasi tujuan, contoh O atau WH-PUSAT}
        {--commit : Terapkan perubahan ke database. Default: DRY-RUN simulasi}
        {--allow-partial : Saat commit, terapkan baris valid dan lewati baris blocking yang gagal validasi}
        {--zero-missing : Nolkan stok SKU x Rak yang ada di sistem tetapi tidak ada di file}
        {--chunk=1000 : Ukuran batch transaksi database}
        {--export= : Custom path untuk file output CSV laporan}
        {--limit=15 : Jumlah contoh yang ditampilkan per jenis masalah}';

    protected $description = 'Validasi dan eksekusi impor stok baseline Jubelio ke cilupbah dengan laporan lengkap & downloadable URL.';

    private const TEMPLATE_SHEET = 'Pengisian Data';

    public function __construct(
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
        protected BinMultiSkuRuleService $binMultiSkuRuleService,
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
        $allowPartial = (bool) $this->option('allow-partial');
        $zeroMissing = (bool) $this->option('zero-missing');
        $chunkSize = max(100, (int) $this->option('chunk'));
        $modeStr = $isCommit ? '<fg=red;options=bold>COMMIT (MENULIS KE DB)</>' : '<fg=yellow;options=bold>DRY-RUN (SIMULASI AMAN)</>';

        $this->line('===============================================================');
        $this->line('  IMPOR BASELINE STOK JUBELIO — CILUPBAH SUPERAPP');
        $this->line('===============================================================');
        $this->line("Mode          : {$modeStr}");
        $this->line("Lokasi Tujuan : {$location->location_name} ({$location->location_code})");
        $this->line("File Sumber   : {$path}");
        $this->line('Zero Missing  : '.($zeroMissing ? 'AKTIF (stok lama yang tidak ada di file akan dinolkan)' : 'NON-AKTIF'));
        $this->newLine();

        $source = $this->readRows($path, $location->id);
        $rows = $source['rows'];

        if ($source['source_rows'] === 0) {
            $this->error('Tidak ada baris berisi stok yang bisa dibaca dari file ini.');

            return self::FAILURE;
        }

        $this->line(sprintf('Total baris sumber terbaca: %s', number_format($source['source_rows'])));
        $this->line(sprintf('Baris Qty Aktual > 0: %s', number_format($source['positive_rows'])));
        $this->line(sprintf('Baris Qty Aktual = 0 tanpa stok sistem: %s', number_format($source['zero_rows_skipped'])));
        $this->newLine();

        $inspection = $this->inspect(
            $rows,
            $location->id,
            (bool) ($location->is_small_warehouse ?? false),
            $source['existing_positive_pairs'],
        );
        $inspection = array_merge($inspection, [
            'source_rows' => $source['source_rows'],
            'positive_rows' => $source['positive_rows'],
            'explicit_zero_rows' => $source['explicit_zero_rows'],
            'zero_rows_retained' => $source['zero_rows_retained'],
            'zero_rows_skipped' => $source['zero_rows_skipped'],
        ]);

        $this->renderSummary($inspection, (int) $this->option('limit'));

        if ($isCommit && (int) $inspection['blocking'] > 0 && ! $allowPartial) {
            $this->error('COMMIT dibatalkan karena masih ada baris bermasalah, tidak ada baris valid yang diterapkan sebagian. Perbaiki file lalu jalankan dry-run kembali.');

            return self::FAILURE;
        }

        if ($isCommit && $allowPartial && (int) $inspection['blocking'] > 0) {
            $this->warn(sprintf(
                'Mode partial aktif: %s baris bermasalah dilewati. Hanya baris valid yang akan diterapkan.',
                number_format($inspection['blocking']),
            ));
        }

        $zeroedItems = [];
        if ($isCommit) {
            $this->newLine();
            $this->info('Memulai eksekusi penulisan stok ke database...');

            $executionResult = $this->executeCommit(
                $inspection['valid_rows'],
                $inspection['all_rows'],
                $location,
                basename($path),
                $chunkSize,
                $zeroMissing,
            );

            $zeroedItems = $executionResult['zeroed_items'] ?? [];
            $this->info('Eksekusi database selesai!');
            $this->line(sprintf('  · Penyesuaian Dibuat : %s item', number_format($executionResult['applied_count'])));
            $this->line(sprintf(
                '  · Dokumen Baseline   : %s',
                $executionResult['adjustment_no'] ?? 'Tidak dibuat (stok sudah sama dengan file)',
            ));
            if ($zeroMissing) {
                $this->line(sprintf('  · Stok Dinolkan      : %s item', number_format(count($zeroedItems))));
            }
            $this->line(sprintf(
                '  · Rack Assignment    : %s item dibuat/diperbarui',
                number_format((int) ($executionResult['rack_assignment_upserted'] ?? 0)),
            ));
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
        $this->info('LAPORAN LENGKAP TELAH DIBUAT');
        $this->line("File Path : {$reportInfo['file_path']}");
        $this->line("Download  : <fg=cyan;options=bold>{$reportInfo['download_url']}</>");
        if (($reportInfo['omitted_rows'] ?? 0) > 0) {
            $this->line(sprintf(
                'Detail non-actionable yang tidak ditulis ke CSV: %s baris (tetap tercatat di ringkasan).',
                number_format($reportInfo['omitted_rows']),
            ));
        }
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
            $this->error('Opsi --location wajib diisi. Contoh: --location=O atau --location=WH-PUSAT');
            $this->listLocations();

            return null;
        }

        $location = DB::table('locations')
            ->where('location_code', $code)
            ->first(['id', 'location_code', 'location_name', 'is_small_warehouse']);

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

    private function readRows(string $path, string $locationId): array
    {
        @ini_set('memory_limit', '512M');

        $existingPositivePairs = $this->existingPositivePairs($locationId);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $source = ($ext === 'csv' || $ext === 'txt')
            ? $this->readCsvRows($path, $existingPositivePairs)
            : $this->readXlsxRows($path, $existingPositivePairs);

        $source['existing_positive_pairs'] = $existingPositivePairs;

        return $source;
    }

    private function readXlsxRows(string $path, array $existingPositivePairs): array
    {
        $worksheet = $this->resolveXlsxWorksheet($path);
        if ($worksheet === null) {
            return $this->emptySourceStats() + ['rows' => []];
        }

        $isTemplate = $worksheet['name'] === self::TEMPLATE_SHEET;
        $sharedStrings = $this->readXlsxSharedStrings($path);
        $rows = [];
        $stats = $this->emptySourceStats();
        $reader = new \XMLReader;

        if (! $reader->open($this->xlsxStreamUri($path, $worksheet['path']), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException("File XLSX tidak dapat dibaca: {$path}");
        }

        try {
            $rowNo = 0;
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowNo++;
                if ($rowNo === 1) {
                    continue;
                }

                $this->collectSourceRow(
                    $this->extractXlsxRow($reader->readOuterXml(), $sharedStrings),
                    $rowNo,
                    $isTemplate,
                    $existingPositivePairs,
                    $rows,
                    $stats,
                );
            }
        } finally {
            $reader->close();
        }

        return $stats + ['rows' => $rows];
    }

    private function resolveXlsxWorksheet(string $path): ?array
    {
        $archive = new \ZipArchive;
        if ($archive->open($path) !== true) {
            throw new \RuntimeException("File XLSX tidak dapat dibuka: {$path}");
        }

        try {
            $workbook = $this->parseXlsxXml($archive->getFromName('xl/workbook.xml'));
            $relationships = $this->parseXlsxXml($archive->getFromName('xl/_rels/workbook.xml.rels'));

            if ($workbook === null || $relationships === null) {
                throw new \RuntimeException("Struktur workbook XLSX tidak valid: {$path}");
            }

            $workbookNamespaces = $workbook->getDocNamespaces(true);
            $mainNamespace = $workbookNamespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $relationshipNamespace = $workbookNamespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $workbook->registerXPathNamespace('xlsx', $mainNamespace);

            $sheets = $workbook->xpath('//xlsx:sheets/xlsx:sheet') ?: [];
            if ($sheets === []) {
                return null;
            }

            $workbook->registerXPathNamespace('xlsx', $mainNamespace);
            $activeView = ($workbook->xpath('//xlsx:bookViews/xlsx:workbookView') ?: [])[0] ?? null;
            $activeIndex = max(0, (int) ($activeView['activeTab'] ?? 0));

            $relationshipNamespaces = $relationships->getDocNamespaces(true);
            $packageNamespace = $relationshipNamespaces[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships';
            $relationships->registerXPathNamespace('rel', $packageNamespace);

            $targets = [];
            foreach ($relationships->xpath('//rel:Relationship') ?: [] as $relationship) {
                $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
            }

            $selected = null;
            foreach ($sheets as $index => $sheet) {
                if ((string) $sheet['name'] === self::TEMPLATE_SHEET) {
                    $selected = $sheet;
                    break;
                }

                if ($index === $activeIndex) {
                    $selected ??= $sheet;
                }
            }
            $selected ??= $sheets[0];

            $relationAttributes = $selected->attributes($relationshipNamespace);
            $relationId = (string) ($relationAttributes['id'] ?? '');
            $target = $targets[$relationId] ?? null;

            if (! is_string($target) || $target === '') {
                throw new \RuntimeException("Worksheet XLSX tidak ditemukan: {$path}");
            }

            $normalizedTarget = ltrim($target, '/');
            $worksheetPath = str_starts_with($normalizedTarget, 'xl/')
                ? $normalizedTarget
                : 'xl/'.$normalizedTarget;

            if ($archive->locateName($worksheetPath) === false) {
                throw new \RuntimeException("File worksheet XLSX tidak ditemukan: {$worksheetPath}");
            }

            return [
                'name' => (string) $selected['name'],
                'path' => $worksheetPath,
            ];
        } finally {
            $archive->close();
        }
    }

    private function parseXlsxXml(string|false $xml): ?\SimpleXMLElement
    {
        if (! is_string($xml) || $xml === '') {
            return null;
        }

        return @simplexml_load_string($xml);
    }

    private function readXlsxSharedStrings(string $path): array
    {
        $reader = new \XMLReader;
        if (! $reader->open($this->xlsxStreamUri($path, 'xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }

        $strings = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                    $strings[] = html_entity_decode(
                        strip_tags($reader->readOuterXml()),
                        ENT_QUOTES | ENT_XML1,
                        'UTF-8',
                    );
                }
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    private function extractXlsxRow(string $rowXml, array $sharedStrings): array
    {
        $values = [];
        $ordinal = 0;

        preg_match_all('/<c\\b([^>]*?)(?:\\/|>(.*?)<\\/c)>/s', $rowXml, $cells, PREG_SET_ORDER);

        foreach ($cells as $cell) {
            $index = $ordinal++;
            if (preg_match('/\\br="([A-Z]+)\\d+"/', $cell[1], $coordinate)) {
                $index = match ($coordinate[1]) {
                    'A' => 0,
                    'D' => 3,
                    'H' => 7,
                    'I' => 8,
                    'J' => 9,
                    default => -1,
                };
            }

            if (! in_array($index, [0, 3, 7, 8, 9], true)) {
                continue;
            }

            $values[$index] = $this->decodeXlsxCell(
                $cell[1],
                $cell[2] ?? '',
                $sharedStrings,
            );
        }

        return $values;
    }

    private function decodeXlsxCell(string $attributes, string $content, array $sharedStrings): ?string
    {
        preg_match('/\\bt="([^"]+)"/', $attributes, $type);
        $cellType = $type[1] ?? null;

        if (preg_match('/<v>(.*?)<\\/v>/s', $content, $value)) {
            $rawValue = html_entity_decode($value[1], ENT_QUOTES | ENT_XML1, 'UTF-8');

            return $cellType === 's'
                ? ($sharedStrings[(int) $rawValue] ?? '')
                : $rawValue;
        }

        if ($cellType === 'inlineStr' && preg_match('/<t[^>]*>(.*?)<\\/t>/s', $content, $value)) {
            return html_entity_decode(strip_tags($value[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return null;
    }

    private function xlsxStreamUri(string $path, string $entry): string
    {
        return "zip://{$path}#{$entry}";
    }

    private function readCsvRows(string $path, array $existingPositivePairs): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $this->emptySourceStats() + ['rows' => []];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return $this->emptySourceStats() + ['rows' => []];
        }

        $headerMap = [];
        foreach ($header as $idx => $colName) {
            $headerMap[strtolower(trim((string) $colName))] = $idx;
        }

        $isTemplate = isset($headerMap['sku']) && isset($headerMap['kode rak']) && isset($headerMap['qty']);
        $rows = [];
        $stats = $this->emptySourceStats();
        $rowNo = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $rowNo++;
            $this->collectSourceRow($raw, $rowNo, $isTemplate, $existingPositivePairs, $rows, $stats, $headerMap);
        }

        fclose($handle);

        $this->line(sprintf('  dibaca %s baris sumber dari CSV', number_format($stats['source_rows'])));
        $this->newLine();

        return $stats + ['rows' => $rows];
    }

    private function emptySourceStats(): array
    {
        return [
            'source_rows' => 0,
            'positive_rows' => 0,
            'explicit_zero_rows' => 0,
            'zero_rows_retained' => 0,
            'zero_rows_skipped' => 0,
        ];
    }

    private function collectSourceRow(
        array $raw,
        int $rowNo,
        bool $isTemplate,
        array $existingPositivePairs,
        array &$rows,
        array &$stats,
        ?array $headerMap = null,
    ): void {
        $headerMap ??= [];

        if ($isTemplate) {
            $sku = trim((string) ($raw[$headerMap['sku'] ?? 0] ?? ''));
            $bin = trim((string) ($raw[$headerMap['kode rak'] ?? 1] ?? ''));
            $qty = (int) ($raw[$headerMap['qty'] ?? 3] ?? 0);
            $fileLocation = null;
        } else {
            $sku = trim((string) ($raw[$headerMap['sku'] ?? 0] ?? ''));
            $fileLocation = trim((string) ($raw[$headerMap['lokasi'] ?? 3] ?? '')) ?: null;
            $binIndex = $headerMap['no rak']
                ?? $headerMap['no_rak']
                ?? $headerMap['kode rak']
                ?? $headerMap['kode_rak']
                ?? $headerMap['rak']
                ?? 7;
            $bin = trim((string) ($raw[$binIndex] ?? ''));
            $actualIndex = $headerMap['qty aktual']
                ?? $headerMap['qty actual']
                ?? $headerMap['stok baru aktual']
                ?? $headerMap['stok_baru_aktual']
                ?? 9;
            $onHandIndex = $headerMap['qty on hand']
                ?? $headerMap['stok saat ini on_hand']
                ?? $headerMap['stok_saat_ini_on_hand']
                ?? 8;
            $qty = isset($raw[$actualIndex]) && $raw[$actualIndex] !== '' && $raw[$actualIndex] !== null
                ? (int) $raw[$actualIndex]
                : (int) ($raw[$onHandIndex] ?? 0);
        }

        if ($sku === '' || $qty < 0) {
            return;
        }

        $stats['source_rows']++;
        if ($qty > 0) {
            $stats['positive_rows']++;
        } else {
            $stats['explicit_zero_rows']++;
            if (! isset($existingPositivePairs[$this->stockPairKey($sku, $bin)])) {
                $stats['zero_rows_skipped']++;
            } else {
                $stats['zero_rows_retained']++;
            }
        }

        $rows[] = [
            'row' => $rowNo,
            'sku' => $sku,
            'bin' => $bin,
            'qty' => $qty,
            'file_location' => $fileLocation,
        ];
    }

    private function existingPositivePairs(string $locationId): array
    {
        $pairs = [];

        DB::table('inventories as i')
            ->join('product_variants as v', 'v.id', '=', 'i.item_id')
            ->join('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->where('i.location_id', $locationId)
            ->where('i.on_hand', '<>', 0)
            ->whereNotNull('b.bin_final_code')
            ->orderBy('i.id')
            ->select(['i.item_id as variant_id', 'i.bin_id', 'i.on_hand', 'v.sku', 'b.bin_final_code'])
            ->cursor()
            ->each(function (object $row) use (&$pairs): void {
                $key = $this->stockPairKey((string) $row->sku, (string) $row->bin_final_code);
                $pairs[$key] ??= [
                    'variant_id' => $row->variant_id,
                    'bin_id' => $row->bin_id,
                    'sku' => $row->sku,
                    'bin_final_code' => $row->bin_final_code,
                    'on_hand' => $row->on_hand,
                ];
            });

        return $pairs;
    }

    private function stockPairKey(string $sku, string $bin): string
    {
        return mb_strtolower(trim($sku))."\0".mb_strtoupper(trim($bin));
    }

    private function inspect(
        array $rows,
        string $locationId,
        bool $strictBinSku,
        array $existingPositivePairs,
    ): array {

        $lookupSkus = array_values(array_unique(array_map(
            fn (array $row): string => $row['sku'],
            $rows,
        )));
        $bins = array_values(array_filter(array_unique(array_column($rows, 'bin'))));

        $variants = $this->lookupVariants($lookupSkus);
        $binsHere = $this->lookupBins($bins, $locationId);
        $binsElsewhere = $this->lookupBinsElsewhere($bins, $locationId, array_keys($binsHere));

        $problems = [
            'sku_hilang' => [],
            'sku_beda_huruf' => [],
            'sku_ganda' => [],
            'sku_nonaktif' => [],
            'rak_hilang' => [],
            'rak_gudang_lain' => [],
            'rak_kosong' => [],
            'rak_inbound' => [],
            'rak_multi_sku' => [],
        ];

        $lowerIndex = [];
        foreach ($variants as $sku => $variant) {
            $lowerIndex[mb_strtolower($sku)][] = $sku;
        }

        $binCodeById = [];
        foreach ($binsHere as $binCode => $bin) {
            $binCodeById[(string) $bin->id] = (string) $binCode;
        }

        $csvItemsByBin = [];
        if ($strictBinSku) {
            foreach ($rows as $row) {
                $variant = $variants[$row['sku']] ?? null;
                if ($variant === null) {
                    $alternatives = $lowerIndex[mb_strtolower($row['sku'])] ?? [];
                    $variant = count($alternatives) === 1 ? ($variants[$alternatives[0]] ?? null) : null;
                }
                $bin = $binsHere[$row['bin']] ?? null;
                if ($variant === null || $bin === null || $bin->is_inbound || strtoupper(trim((string) $bin->bin_final_code)) === 'DEFAULT') {
                    continue;
                }
                $csvItemsByBin[(string) $bin->id][(string) $variant->id] = true;
            }
        }
        $blockedMultiSkuBins = [];
        foreach ($csvItemsByBin as $binId => $itemIds) {
            if (count($itemIds) <= 1) {
                continue;
            }
            $binCode = $binCodeById[$binId] ?? null;
            if ($binCode !== null && ! $this->binMultiSkuRuleService->allowsMultiSkuCode($locationId, $binCode)) {
                $blockedMultiSkuBins[$binId] = true;
            }
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
                    $key = $inv->item_id.':'.($inv->bin_id ?? 'null');
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
        $zeroRowsWithoutCurrentStock = 0;

        foreach ($rows as $row) {
            $sku = $row['sku'];
            $bin = $row['bin'];
            $isZero = (int) $row['qty'] === 0;
            $existingPair = $isZero
                ? ($existingPositivePairs[$this->stockPairKey($sku, $bin)] ?? null)
                : null;
            $blocked = false;
            $status = 'VALID';
            $notes = 'Siap diimpor';
            $variantId = null;
            $curOnHand = 0.0;

            if (! $isZero) {
                if (! isset($variants[$sku])) {
                    $alternatives = $lowerIndex[mb_strtolower($sku)] ?? [];

                    if ($alternatives !== []) {
                        $notes = 'SKU beda huruf besar/kecil (di sistem: '.implode(', ', $alternatives).')';
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
                    $variantId = $variant->id;

                    if ($variant->jumlah > 1) {
                        $problems['sku_ganda'][] = $row + ['catatan' => "{$variant->jumlah} varian memakai SKU ini"];
                    }

                    if ($variant->is_active === false) {
                        $problems['sku_nonaktif'][] = $row + ['catatan' => 'varian berstatus non-aktif'];
                    }
                }
            } elseif ($existingPair !== null) {
                $variantId = $existingPair['variant_id'];
                $curOnHand = (float) $existingPair['on_hand'];
            } else {
                $variant = $variants[$sku] ?? null;
                if ($variant === null) {
                    $alternatives = $lowerIndex[mb_strtolower($sku)] ?? [];
                    if (count($alternatives) === 1) {
                        $variant = $variants[$alternatives[0]] ?? null;
                    }
                }
                $variantId = $variant?->id;
            }

            $resolvedBinId = null;

            if ($bin === '') {
                $notes = 'kode rak kosong; import baseline wajib memakai rak final, bukan DEFAULT/inbound';
                $problems['rak_kosong'][] = $row + ['catatan' => $notes];
                $status = 'DITOLAK_RAK_KOSONG';
                $blocked = true;
            } elseif (! isset($binsHere[$bin])) {
                if (isset($binsElsewhere[$bin])) {
                    $notes = 'Kode rak milik gudang lain: '.$binsElsewhere[$bin];
                    $problems['rak_gudang_lain'][] = $row + ['catatan' => $notes];
                    $status = 'DITOLAK_RAK_GUDANG_LAIN';
                } else {
                    $notes = 'Kode rak belum ada di sistem';
                    $problems['rak_hilang'][] = $row + ['catatan' => $notes];
                    $status = 'DITOLAK_RAK_HILANG';
                }

                $blocked = true;
            } else {
                $candidateBin = $binsHere[$bin];
                if ((bool) ($candidateBin->is_inbound ?? false)
                    || strtoupper(trim((string) ($candidateBin->bin_final_code ?? ''))) === 'DEFAULT') {
                    $notes = 'rak inbound/DEFAULT tidak boleh menjadi target import baseline; lakukan putaway ke rak final terlebih dahulu';
                    $problems['rak_inbound'][] = $row + ['catatan' => $notes];
                    $status = 'DITOLAK_RAK_INBOUND';
                    $blocked = true;
                } else {
                    $resolvedBinId = $candidateBin->id;
                }
            }

            if (! $blocked && $strictBinSku && isset($blockedMultiSkuBins[(string) $resolvedBinId])) {
                $notes = 'Rak Gudang Kecil berisi lebih dari satu SKU dan tidak memiliki pattern multi-SKU.';
                $problems['rak_multi_sku'][] = $row + ['catatan' => $notes];
                $status = 'DITOLAK_RAK_MULTI_SKU';
                $blocked = true;
            }

            $pairKey = $variantId.':'.($resolvedBinId ?? 'null');
            if (! $isZero || $existingPair === null) {
                $curOnHand = (float) ($currentStockMap[$pairKey] ?? 0.0);
            }
            $targetOnHand = (float) $row['qty'];
            $delta = $targetOnHand - $curOnHand;

            if ($isZero && ! $blocked && $existingPair === null) {
                $status = 'ZERO_TANPA_STOK_SISTEM';
                $notes = 'Rak valid; SKU tidak divalidasi karena Qty 0 dan tidak ada stok lama pada pasangan SKU-rak ini. Tidak ada perubahan yang perlu ditulis.';
                $zeroRowsWithoutCurrentStock++;
            }

            $evaluatedRow = $row + [
                'status' => $status,
                'catatan' => $notes,
                'blocked' => $blocked,
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
            } elseif ($isZero && $existingPair === null) {

                continue;
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
            'zero_rows_without_current_stock' => $zeroRowsWithoutCurrentStock,
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
                ->get(['id', 'bin_final_code', 'is_inbound']);

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
            ['Total Baris di File', number_format($report['source_rows'] ?? $report['total_rows'])],
            ['Baris Qty Aktual > 0', number_format($report['positive_rows'] ?? $report['total_rows'])],
            ['Baris Qty Aktual = 0', number_format($report['explicit_zero_rows'] ?? 0)],
            ['Baris Qty 0 yang Perlu Dinolkan', number_format($report['zero_rows_retained'] ?? 0)],
            ['Baris Qty 0 Hanya Cek Rak / Tidak Ditulis', number_format($report['zero_rows_without_current_stock'] ?? $report['zero_rows_skipped'] ?? 0)],
            ['Baris yang Perlu Divalidasi', number_format($report['total_rows'])],
            ['Total Qty di File', number_format($report['total_qty']).' pcs'],
            ['Baris Valid (Lolos)', number_format($report['ok_rows'])],
            ['Qty yang Siap Masuk', number_format($report['ok_qty']).' pcs'],
            ['Baris Bermasalah / Ditolak', number_format($report['blocking'])],
            ['Qty yang Ditolak', number_format($report['lost_qty']).' pcs'],
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
                    number_format($i['current_on_hand']).' pcs',
                    number_format($i['target_on_hand']).' pcs',
                    ($i['delta'] > 0 ? '+' : '').number_format($i['delta']).' pcs',
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
            'rak_kosong' => 'Kode rak kosong (baris DITOLAK)',
            'rak_inbound' => 'Rak inbound/DEFAULT (baris DITOLAK)',
            'rak_multi_sku' => 'Rak Gudang Kecil tidak mengizinkan multi-SKU (baris DITOLAK)',
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
        array $allRows,
        object $location,
        string $sourceFilename,
        int $chunkSize,
        bool $zeroMissing,
    ): array {
        $hasStockChange = collect($validRows)
            ->contains(fn (array $row): bool => (float) $row['delta'] !== 0.0);

        $assignmentCandidates = [];
        $protectedItemIds = [];
        foreach ($allRows as $row) {
            if (($row['blocked'] ?? false) === true) {
                if (! empty($row['variant_id'])) {
                    $protectedItemIds[(string) $row['variant_id']] = true;
                }

                continue;
            }

            if (! empty($row['variant_id']) && ! empty($row['bin_id'])) {
                $itemId = (string) $row['variant_id'];
                $binId = (string) $row['bin_id'];
                $assignmentCandidates[$itemId][$binId] = max(
                    (float) ($assignmentCandidates[$itemId][$binId] ?? 0),
                    (float) ($row['qty'] ?? 0),
                );
            }
        }

        $assignmentRows = $this->selectRackAssignments($assignmentCandidates, (string) $location->id);

        if (! $hasStockChange && ! $zeroMissing && $assignmentRows === []) {
            return [
                'adjustment_no' => null,
                'applied_count' => 0,
                'zeroed_items' => [],
                'rack_assignment_upserted' => 0,
            ];
        }

        if (! $hasStockChange && ! $zeroMissing) {
            return [
                'adjustment_no' => null,
                'applied_count' => 0,
                'zeroed_items' => [],
                'rack_assignment_upserted' => $this->syncRackAssignments($assignmentRows, $location, $chunkSize),
            ];
        }

        $timestamp = date('YmdHis');
        $adjustmentNo = 'ADJ-BASELINE-'.$location->location_code.'-'.$timestamp;

        $adjustment = StockAdjustment::create([
            'adjustment_no' => $adjustmentNo,
            'transaction_date' => now(),
            'location_id' => $location->id,
            'is_beginning_balance' => true,
            'notes' => 'Import Baseline Stok Jubelio '.$sourceFilename,
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

                    $pairKey = $itemId.':'.($binId ?? 'null');
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
                ->where('on_hand', '<>', 0)
                ->when(
                    $protectedItemIds !== [],
                    fn ($query) => $query->whereNotIn('item_id', array_keys($protectedItemIds)),
                )
                ->select(['id', 'item_id', 'bin_id', 'on_hand', 'avg_cost'])
                ->orderBy('id');

            $existingInventories->chunkById($chunkSize, function ($chunk) use (
                $adjustment,
                $location,
                &$seenPairs,
                &$zeroedItems,
            ): void {
                DB::transaction(function () use ($chunk, $adjustment, $location, &$seenPairs, &$zeroedItems): void {
                    foreach ($chunk as $inv) {
                        $pairKey = $inv->item_id.':'.($inv->bin_id ?? 'null');
                        if (isset($seenPairs[$pairKey])) {
                            continue;
                        }

                        $systemQty = (float) $inv->on_hand;
                        $diff = -$systemQty;
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

                        $zeroedItems[] = [
                            'item_id' => $inv->item_id,
                            'bin_id' => $inv->bin_id,
                            'qty_sebelumnya' => $systemQty,
                        ];
                    }
                });
            }, 'id', 'id');
        }

        return [
            'adjustment_no' => $adjustmentNo,
            'applied_count' => $appliedCount,
            'zeroed_items' => $zeroedItems,
            'rack_assignment_upserted' => $this->syncRackAssignments($assignmentRows, $location, $chunkSize),
        ];
    }

    private function syncRackAssignments(array $assignmentRows, object $location, int $chunkSize): int
    {
        if ($assignmentRows === [] || ! Schema::hasTable('sku_rack_assignments')) {
            return 0;
        }

        $upserted = 0;
        foreach (array_chunk(array_values($assignmentRows), $chunkSize) as $chunk) {
            $now = now();
            $payload = array_map(static fn (array $row): array => [
                'id' => (string) Str::uuid(),
                'location_id' => (string) $location->id,
                'item_id' => $row['item_id'],
                'bin_id' => $row['bin_id'],
                'assigned_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            DB::table('sku_rack_assignments')->upsert(
                $payload,
                ['location_id', 'item_id'],
                ['bin_id', 'updated_at'],
            );
            $upserted += count($payload);
        }

        return $upserted;
    }

    private function selectRackAssignments(array $candidates, string $locationId): array
    {
        if ($candidates === [] || ! Schema::hasTable('sku_rack_assignments')) {
            return [];
        }

        $existing = DB::table('sku_rack_assignments')
            ->where('location_id', $locationId)
            ->whereIn('item_id', array_keys($candidates))
            ->pluck('bin_id', 'item_id')
            ->all();

        $selected = [];
        foreach ($candidates as $itemId => $bins) {
            $existingBin = $existing[$itemId] ?? null;
            if ($existingBin !== null && array_key_exists((string) $existingBin, $bins)) {
                $selected[$itemId] = ['item_id' => $itemId, 'bin_id' => (string) $existingBin];

                continue;
            }

            uksort($bins, static function (string $left, string $right) use ($bins): int {
                $quantityOrder = $bins[$right] <=> $bins[$left];

                return $quantityOrder !== 0 ? $quantityOrder : strcmp($left, $right);
            });
            $selected[$itemId] = ['item_id' => $itemId, 'bin_id' => (string) array_key_first($bins)];
        }

        return $selected;
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

        $omittedRows = 0;
        foreach ($inspection['all_rows'] as $row) {
            if ($this->shouldOmitReportRow($row)) {
                $omittedRows++;

                continue;
            }

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
                    'ITEM_ID: '.$z['item_id'],
                    'BIN_ID: '.($z['bin_id'] ?? 'default'),
                    '0',
                    'ZEROED_MISSING',
                    'Dinolkan dari sisa stok lama '.$z['qty_sebelumnya'].' pcs',
                ]);
            }
        }

        fclose($handle);

        $appUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $downloadUrl = "{$appUrl}/storage/{$storageRelDir}/{$filename}";

        return [
            'file_path' => $targetPath,
            'download_url' => $downloadUrl,
            'omitted_rows' => $omittedRows,
        ];
    }

    private function shouldOmitReportRow(array $row): bool
    {
        if (($row['status'] ?? null) === 'ZERO_TANPA_STOK_SISTEM') {
            return true;
        }

        return (int) ($row['qty'] ?? 0) === 0
            && ($row['status'] ?? null) === 'DITOLAK_RAK_HILANG';
    }
}
