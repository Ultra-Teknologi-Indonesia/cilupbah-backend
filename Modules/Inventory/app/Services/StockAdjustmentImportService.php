<?php

namespace Modules\Inventory\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Import Penyesuaian Stok — template terpadu delta + final.
 *
 * Aturan per baris: user WAJIB isi salah satu dari `delta_qty` atau `final_qty`.
 * - delta_qty: selisih (+/-) yang mau ditambah/dikurangi dari on_hand sekarang.
 * - final_qty: stok akhir absolut (menimpa on_hand).
 * Sistem normalize keduanya ke `actual_qty` untuk masuk ke StockAdjustmentService::create.
 */
class StockAdjustmentImportService
{
    public const CACHE_PREFIX = 'stock-adjustment-import:';
    public const CACHE_TTL_MINUTES = 30;
    public const MAX_ROWS = 1000;
    public const DATA_SHEET_NAME = 'Pengisian Data';

    public function __construct(
        protected InventoryRepository $inventoryRepository,
    ) {}

    /**
     * Parse + validasi file. Cache hasil pakai token supaya confirm bisa reuse
     * tanpa upload ulang.
     *
     * @return array{token: string, items: array, errors: array, warnings: array, summary: array}
     */
    public function preview(UploadedFile $file, string $locationId): array
    {
        $rows = $this->readDataRows($file);

        if (empty($rows)) {
            throw new \Exception('Sheet "' . self::DATA_SHEET_NAME . '" kosong atau tidak ditemukan.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new \Exception('Maksimal ' . self::MAX_ROWS . ' baris. File berisi ' . count($rows) . ' baris.');
        }

        // Batch lookup SKU → variant untuk hindari N+1.
        $skus = collect($rows)->pluck('item_code')->filter()->map(fn ($s) => trim((string) $s))->unique()->values()->all();
        $variants = ProductVariant::whereIn('sku', $skus)
            ->get(['id', 'sku', 'product_id'])
            ->keyBy(fn ($v) => strtolower($v->sku));

        $variantsById = $variants->keyBy('id');

        // Batch lookup bin by code di lokasi terpilih
        $binCodes = collect($rows)->pluck('bin_final_code')->filter()->map(fn ($s) => trim((string) $s))->unique()->values()->all();
        $bins = LocationBin::where('location_id', $locationId)
            ->whereIn('bin_final_code', $binCodes)
            ->get(['id', 'bin_final_code'])
            ->keyBy('bin_final_code');

        // Ambil product names untuk display
        $productNames = \Modules\Product\Models\Product::whereIn('id', $variants->pluck('product_id')->filter()->unique())
            ->pluck('name', 'id');

        $items = [];
        $errors = [];
        $warnings = [];

        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 2; // Excel row (header di baris 1, data mulai baris 2)

            // 1. item_code wajib
            $sku = trim((string) ($row['item_code'] ?? ''));
            if ($sku === '') {
                $errors[] = ['row' => $rowNo, 'field' => 'item_code', 'error' => 'SKU wajib diisi'];
                continue;
            }

            $variant = $variants->get(strtolower($sku));
            if (! $variant) {
                $errors[] = ['row' => $rowNo, 'field' => 'item_code', 'error' => "SKU '{$sku}' tidak ditemukan"];
                continue;
            }

            // 2. XOR delta vs final
            $deltaRaw = $row['delta_qty'] ?? null;
            $finalRaw = $row['final_qty'] ?? null;

            $hasDelta = $deltaRaw !== null && $deltaRaw !== '';
            $hasFinal = $finalRaw !== null && $finalRaw !== '';

            if (! $hasDelta && ! $hasFinal) {
                $errors[] = ['row' => $rowNo, 'field' => 'delta_qty|final_qty', 'error' => 'Isi salah satu: delta_qty atau final_qty'];
                continue;
            }

            if ($hasDelta && $hasFinal) {
                $errors[] = ['row' => $rowNo, 'field' => 'delta_qty|final_qty', 'error' => 'Isi HANYA salah satu (delta_qty atau final_qty), bukan keduanya'];
                continue;
            }

            $mode = $hasDelta ? 'DELTA' : 'FINAL';
            $inputValue = $mode === 'DELTA' ? (int) $deltaRaw : (int) $finalRaw;

            if ($mode === 'FINAL' && $inputValue < 0) {
                $errors[] = ['row' => $rowNo, 'field' => 'final_qty', 'error' => 'final_qty tidak boleh negatif'];
                continue;
            }

            // 3. Resolve bin — kalau kosong, pakai rak utama item
            $binCode = trim((string) ($row['bin_final_code'] ?? ''));
            $binId = null;
            $binResolved = null;

            if ($binCode !== '') {
                $bin = $bins->get($binCode);
                if (! $bin) {
                    $errors[] = ['row' => $rowNo, 'field' => 'bin_final_code', 'error' => "Rak '{$binCode}' tidak ada di lokasi terpilih"];
                    continue;
                }
                $binId = $bin->id;
                $binResolved = $bin->bin_final_code;
            } else {
                // Cari rak utama = inventory paling awal dengan on_hand > 0 (skip DEFAULT)
                $primary = Inventory::where('item_id', $variant->id)
                    ->where('location_id', $locationId)
                    ->whereNotNull('bin_id')
                    ->where('on_hand', '>', 0)
                    ->with('bin:id,bin_final_code')
                    ->whereHas('bin', fn ($q) => $q->where('bin_final_code', '!=', 'DEFAULT'))
                    ->orderBy('created_at')
                    ->first();

                if (! $primary) {
                    $primary = Inventory::where('item_id', $variant->id)
                        ->where('location_id', $locationId)
                        ->whereNotNull('bin_id')
                        ->with('bin:id,bin_final_code')
                        ->orderBy('created_at')
                        ->first();
                }

                if (! $primary) {
                    $errors[] = ['row' => $rowNo, 'field' => 'bin_final_code', 'error' => 'Belum ada rak untuk item ini di lokasi terpilih — isi bin_final_code manual'];
                    continue;
                }
                $binId = $primary->bin_id;
                $binResolved = $primary->bin?->bin_final_code;
            }

            // 4. Hitung system_qty di bin yang dipilih
            $inventory = Inventory::where('item_id', $variant->id)
                ->where('location_id', $locationId)
                ->where('bin_id', $binId)
                ->first();

            $systemQty = $inventory ? (int) $inventory->on_hand : 0;

            // 5. Normalize ke actual_qty
            $actualQty = $mode === 'DELTA' ? $systemQty + $inputValue : $inputValue;

            if ($actualQty < 0) {
                $errors[] = ['row' => $rowNo, 'field' => 'delta_qty', 'error' => "Delta menyebabkan stok minus (on_hand={$systemQty} + delta={$inputValue} = {$actualQty})"];
                continue;
            }

            // 6. HPP opsional
            $hppRaw = $row['hpp'] ?? null;
            $unitCost = null;
            if ($hppRaw !== null && $hppRaw !== '') {
                $unitCost = (float) $hppRaw;
                if ($unitCost < 0) {
                    $errors[] = ['row' => $rowNo, 'field' => 'hpp', 'error' => 'hpp tidak boleh negatif'];
                    continue;
                }
            }

            $noteText = trim((string) ($row['notes'] ?? ''));

            // Warning: item inactive
            if (isset($variant->is_active) && ! $variant->is_active) {
                $warnings[] = ['row' => $rowNo, 'field' => 'item_code', 'warning' => "SKU '{$sku}' berstatus non-aktif"];
            }

            $items[] = [
                'row_no'        => $rowNo,
                'item_id'       => $variant->id,
                'sku'           => $variant->sku,
                'product_name'  => $productNames[$variant->product_id] ?? '',
                'bin_id'        => $binId,
                'bin_code'      => $binResolved,
                'mode'          => $mode,
                'input_value'   => $inputValue,
                'system_qty'    => $systemQty,
                'actual_qty'    => $actualQty,
                'difference'    => $actualQty - $systemQty,
                'unit_cost'     => $unitCost,
                'notes'         => $noteText !== '' ? $noteText : null,
            ];
        }

        $token = 'imp_' . Str::uuid()->toString();
        $summary = [
            'total_rows' => count($rows),
            'valid'      => count($items),
            'errors'     => count($errors),
            'warnings'   => count($warnings),
        ];

        Cache::put(self::CACHE_PREFIX . $token, [
            'location_id' => $locationId,
            'items'       => $items,
            'summary'     => $summary,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'token'    => $token,
            'items'    => $items,
            'errors'   => $errors,
            'warnings' => $warnings,
            'summary'  => $summary,
        ];
    }

    /**
     * Ambil preview dari cache untuk dijadikan payload StockAdjustmentService::create.
     */
    public function getPreview(string $token): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $token);
    }

    public function forgetPreview(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX . $token);
    }

    /**
     * Baca sheet "Pengisian Data" dan return array of rows dengan key = header.
     */
    protected function readDataRows(UploadedFile $file): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());

        if (! $spreadsheet->sheetNameExists(self::DATA_SHEET_NAME)) {
            throw new \Exception('Sheet "' . self::DATA_SHEET_NAME . '" tidak ditemukan.');
        }

        $sheet = $spreadsheet->getSheetByName(self::DATA_SHEET_NAME);
        $data = $sheet->toArray(null, true, true, false);

        if (empty($data)) return [];

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($data));

        $rows = [];
        foreach ($data as $row) {
            // Skip baris kosong
            $nonEmpty = array_filter($row, fn ($v) => $v !== null && $v !== '');
            if (empty($nonEmpty)) continue;

            $assoc = [];
            foreach ($header as $i => $col) {
                if ($col === '') continue;
                $assoc[$col] = $row[$i] ?? null;
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * Bikin file template kosong siap-download.
     */
    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        // Sheet 1: Instruksi
        $instr = $spreadsheet->getActiveSheet();
        $instr->setTitle('Instruksi');
        $instr->setCellValue('A1', 'Import Penyesuaian Stok Cilupbah');
        $instr->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $instrLines = [
            '',
            '1. Buka sheet "Pengisian Data" untuk mengisi data penyesuaian.',
            '2. Setiap baris mewakili 1 item + 1 rak.',
            '3. WAJIB isi salah satu: delta_qty ATAU final_qty (tidak boleh keduanya).',
            '4. Kolom bin_final_code boleh dikosongkan — sistem otomatis pakai rak utama.',
            '5. Baca sheet "Tata Cara Pengisian" untuk detail tiap kolom.',
            '',
            'Legenda:',
            '  Kolom wajib = latar orange',
            '  Kolom opsional = latar kuning',
        ];
        $r = 2;
        foreach ($instrLines as $line) {
            $instr->setCellValue("A{$r}", $line);
            $r++;
        }
        $instr->getColumnDimension('A')->setWidth(90);

        // Sheet 2: Tata Cara Pengisian
        $tata = $spreadsheet->createSheet();
        $tata->setTitle('Tata Cara Pengisian');
        $tataRows = [
            ['Nama Kolom', 'Wajib', 'Tipe', 'Contoh', 'Keterangan'],
            ['item_code', 'Ya', 'Text', 'SKU-12345', 'SKU varian yang mau disesuaikan'],
            ['bin_final_code', 'Opsional', 'Text', 'L1-B1-K1-R2', 'Kosongkan → sistem pakai rak utama otomatis'],
            ['delta_qty', 'Salah satu', 'Integer (+/-)', '10 atau -5', 'Selisih tambah/kurang dari on_hand sekarang'],
            ['final_qty', 'Salah satu', 'Integer ≥0', '100', 'Stok akhir absolut (menimpa on_hand)'],
            ['hpp', 'Opsional', 'Decimal', '2000', 'Harga pokok baru. Delta positif → weighted-avg recalc'],
            ['notes', 'Opsional', 'Text', 'Opname 2026-01', 'Alasan per baris'],
        ];
        foreach ($tataRows as $i => $row) {
            $rowNum = $i + 1;
            foreach ($row as $j => $val) {
                $col = chr(65 + $j);
                $tata->setCellValue("{$col}{$rowNum}", $val);
            }
        }
        $tata->getStyle('A1:E1')->getFont()->setBold(true);
        $tata->getStyle('A1:E1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E5E5E5');
        foreach (['A','B','C','D','E'] as $col) {
            $tata->getColumnDimension($col)->setAutoSize(true);
        }

        // Note khusus XOR
        $tata->setCellValue('A10', 'PENTING: delta_qty DAN final_qty adalah "salah satu" — isi hanya salah satu per baris.');
        $tata->getStyle('A10')->getFont()->setBold(true)->getColor()->setRGB('B22222');
        $tata->mergeCells('A10:E10');

        // Sheet 3: Pengisian Data
        $data = $spreadsheet->createSheet();
        $data->setTitle(self::DATA_SHEET_NAME);
        $headers = ['item_code', 'bin_final_code', 'delta_qty', 'final_qty', 'hpp', 'notes'];
        foreach ($headers as $j => $h) {
            $col = chr(65 + $j);
            $data->setCellValue("{$col}1", $h);
        }
        $data->getStyle('A1:F1')->getFont()->setBold(true);
        $data->getStyle('A1:F1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFF2CC');
        // Kolom wajib item_code + salah satu delta/final → orange
        $data->getStyle('A1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFD9B3');
        $data->getStyle('C1:D1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFD9B3');

        // Contoh baris
        $examples = [
            ['SKU-EXAMPLE-1', '', 10, '', 2000, 'Contoh: mode DELTA (tambah 10 unit)'],
            ['SKU-EXAMPLE-2', 'L1-B1-K1-R2', -3, '', '', 'Contoh: mode DELTA negatif dengan rak spesifik'],
            ['SKU-EXAMPLE-3', '', '', 100, 2500, 'Contoh: mode FINAL (set stok akhir jadi 100)'],
        ];
        foreach ($examples as $i => $row) {
            $rowNum = $i + 2;
            foreach ($row as $j => $val) {
                $col = chr(65 + $j);
                $data->setCellValue("{$col}{$rowNum}", $val);
            }
        }
        foreach (range('A', 'F') as $col) {
            $data->getColumnDimension($col)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(2);

        return $spreadsheet;
    }
}
