<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class ValidateBaselineImport extends Command
{
    protected $signature = 'inventory:validate-baseline
        {file : Path file Excel (ekspor Jubelio atau template impor penyesuaian stok)}
        {--location= : Kode lokasi tujuan, contoh O atau WH-PUSAT}
        {--export= : Tulis baris bermasalah ke file CSV}
        {--limit=15 : Jumlah contoh yang ditampilkan per jenis masalah}';

    protected $description = 'Simulasi impor stok baseline tanpa menulis apa pun. Memeriksa SKU, kode rak, dan kecocokan gudang persis seperti tahap preview importer.';

    private const TEMPLATE_SHEET = 'Pengisian Data';

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

        $this->line("Lokasi tujuan : {$location->location_name} ({$location->location_code})");
        $this->line("File          : {$path}");
        $this->newLine();

        $rows = $this->readRows($path);

        if ($rows === []) {
            $this->error('Tidak ada baris berisi stok yang bisa dibaca dari file ini.');

            return self::FAILURE;
        }

        $this->line(sprintf('Baris ber-stok terbaca: %s', number_format(count($rows))));
        $this->newLine();

        $report = $this->inspect($rows, $location->id);

        $this->renderReport($report, (int) $this->option('limit'));

        if ($exportPath = $this->option('export')) {
            $this->exportProblems($report, (string) $exportPath);
        }

        return $report['blocking'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveLocation(): ?object
    {
        $code = trim((string) $this->option('location'));

        if ($code === '') {
            $this->error('Opsi --location wajib diisi. Contoh: --location=O');
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
        $this->table(['Kode', 'Nama'], $rows);
    }

    private function readRows(string $path): array
    {
        @ini_set('memory_limit', '1024M');

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);

        $isTemplate = in_array(self::TEMPLATE_SHEET, $reader->listWorksheetNames($path), true);

        if ($isTemplate) {
            $reader->setLoadSheetsOnly(self::TEMPLATE_SHEET);
        }

        $columns = $isTemplate ? ['A', 'B', 'C', 'D'] : ['A', 'D', 'H', 'I'];
        $lastColumn = $isTemplate ? 'D' : 'I';
        $totalRows = $this->countRows($reader, $path, $isTemplate);

        $rows = [];
        $chunkSize = 20000;
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
                    $qty = (int) ($raw[8] ?? 0);
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

    private function countRows(BaseReader $reader, string $path, bool $isTemplate): int
    {
        foreach ($reader->listWorksheetInfo($path) as $info) {
            if (! $isTemplate || $info['worksheetName'] === self::TEMPLATE_SHEET) {
                return (int) $info['totalRows'];
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

        $okRows = 0;
        $okQty = 0;
        $lostQty = 0;
        $blockedRows = 0;
        $fileLocations = [];

        foreach ($rows as $row) {
            $sku = $row['sku'];
            $bin = $row['bin'];
            $blocked = false;

            if ($row['file_location'] !== null) {
                $fileLocations[$row['file_location']] = ($fileLocations[$row['file_location']] ?? 0) + 1;
            }

            if (! isset($variants[$sku])) {
                $alternatives = $lowerIndex[mb_strtolower($sku)] ?? [];

                if ($alternatives !== []) {
                    $problems['sku_beda_huruf'][] = $row + ['catatan' => 'di sistem tertulis '.implode(', ', $alternatives)];
                } else {
                    $problems['sku_hilang'][] = $row + ['catatan' => 'SKU tidak terdaftar'];
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

            if ($bin === '') {
                $problems['rak_kosong'][] = $row + ['catatan' => 'kode rak kosong, importer akan menebak rak utama'];
            } elseif (! isset($binsHere[$bin])) {
                if (isset($binsElsewhere[$bin])) {
                    $problems['rak_gudang_lain'][] = $row + ['catatan' => 'rak ini milik '.$binsElsewhere[$bin]];
                } else {
                    $problems['rak_hilang'][] = $row + ['catatan' => 'kode rak belum ada di sistem'];
                }

                $blocked = true;
            }

            if ($blocked) {
                $blockedRows++;
                $lostQty += $row['qty'];
            } else {
                $okRows++;
                $okQty += $row['qty'];
            }
        }

        $blocking = $blockedRows;

        return [
            'total_rows' => count($rows),
            'total_qty' => array_sum(array_column($rows, 'qty')),
            'ok_rows' => $okRows,
            'ok_qty' => $okQty,
            'lost_qty' => $lostQty,
            'blocking' => $blocking,
            'problems' => $problems,
            'file_locations' => $fileLocations,
        ];
    }

    private function lookupVariants(array $skus): array
    {
        $found = [];

        foreach (array_chunk($skus, 2000) as $chunk) {
            $rows = DB::table('product_variants')
                ->whereIn('sku', $chunk)
                ->groupBy('sku')
                ->get([
                    'sku',
                    DB::raw('count(*) as jumlah'),
                    DB::raw('bool_or(coalesce(is_active, true)) as is_active'),
                ]);

            foreach ($rows as $row) {
                $found[$row->sku] = $row;
            }
        }

        $missing = array_values(array_diff($skus, array_keys($found)));

        foreach (array_chunk($missing, 2000) as $chunk) {
            $lowered = array_map('mb_strtolower', $chunk);

            $rows = DB::table('product_variants')
                ->whereIn(DB::raw('lower(sku)'), $lowered)
                ->get(['sku']);

            foreach ($rows as $row) {
                if (! isset($found[$row->sku])) {
                    $found[$row->sku] = (object) ['sku' => $row->sku, 'jumlah' => 1, 'is_active' => true];
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
                ->get(['bin_final_code']);

            foreach ($rows as $row) {
                $found[$row->bin_final_code] = true;
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

    private function renderReport(array $report, int $limit): void
    {
        $this->table(['Ringkasan', 'Nilai'], [
            ['Baris terbaca', number_format($report['total_rows'])],
            ['Total qty di file', number_format($report['total_qty']).' pcs'],
            ['Baris lolos', number_format($report['ok_rows'])],
            ['Qty yang akan masuk', number_format($report['ok_qty']).' pcs'],
            ['Qty yang akan HILANG', number_format($report['lost_qty']).' pcs'],
            ['Baris ditolak', number_format($report['blocking'])],
        ]);

        if (count($report['file_locations']) > 1) {
            $this->newLine();
            $this->warn('File ini memuat lebih dari satu gudang. Importer hanya menulis ke satu lokasi:');

            foreach ($report['file_locations'] as $name => $count) {
                $this->line(sprintf('  · %s — %s baris', $name, number_format($count)));
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
                $this->line(sprintf('  … %s baris lain tidak ditampilkan. Pakai --export untuk daftar lengkap.', number_format(count($items) - $limit)));
            }
        }

        $this->newLine();

        if ($report['blocking'] === 0) {
            $this->info('Tidak ada baris yang akan ditolak. File ini aman diimpor.');

            return;
        }

        $this->error(sprintf(
            '%s baris akan ditolak diam-diam saat impor, setara %s pcs yang tidak akan tercatat.',
            number_format($report['blocking']),
            number_format($report['lost_qty']),
        ));
    }

    private function exportProblems(array $report, string $path): void
    {
        $handle = fopen($path, 'w');

        fputcsv($handle, ['jenis', 'baris', 'sku', 'kode_rak', 'qty', 'catatan']);

        foreach ($report['problems'] as $jenis => $items) {
            foreach ($items as $item) {
                fputcsv($handle, [$jenis, $item['row'], $item['sku'], $item['bin'], $item['qty'], $item['catatan']]);
            }
        }

        fclose($handle);

        $this->info("Daftar lengkap ditulis ke: {$path}");
    }
}
