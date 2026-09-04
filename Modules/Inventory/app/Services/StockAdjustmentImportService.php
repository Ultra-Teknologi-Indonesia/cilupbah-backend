<?php

namespace Modules\Inventory\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;
use Modules\Inventory\Exceptions\NegativeStockAdjustmentException;
use Modules\Inventory\Support\StockAdjustmentRule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class StockAdjustmentImportService
{
    public const CACHE_PREFIX = 'stock-adjustment-import:';
    public const CACHE_TTL_MINUTES = 30;
    public const MAX_ROWS = 1000;
    public const DATA_SHEET_NAME = 'Pengisian Data';

    public function __construct(
        protected InventoryRepository $inventoryRepository,
        protected StockAdjustmentRule $stockAdjustmentRule,
    ) {}

    public function preview(UploadedFile $file, string $locationId, ?string $actorId = null): array
    {
        $rows = $this->readDataRows($file);

        if (empty($rows)) {
            throw new \Exception('Sheet "' . self::DATA_SHEET_NAME . '" kosong atau tidak ditemukan.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new \Exception('Maksimal ' . self::MAX_ROWS . ' baris. File berisi ' . count($rows) . ' baris.');
        }

        $skus = collect($rows)->pluck('item_code')->filter()->map(fn ($s) => trim((string) $s))->unique()->values()->all();
        $variants = ProductVariant::whereIn('sku', $skus)
            ->whereHas('product', fn ($query) => $query->whereNull('deleted_at'))
            ->get(['id', 'sku', 'product_id'])
            ->keyBy(fn ($v) => strtolower($v->sku));

        $variantsById = $variants->keyBy('id');

        $binCodes = collect($rows)->pluck('bin_final_code')->filter()->map(fn ($s) => trim((string) $s))->unique()->values()->all();
        $bins = LocationBin::where('location_id', $locationId)
            ->whereIn('bin_final_code', $binCodes)
            ->get(['id', 'bin_final_code', 'is_inbound'])
            ->keyBy('bin_final_code');

        $productNames = \Modules\Product\Models\Product::whereIn('id', $variants->pluck('product_id')->filter()->unique())
            ->pluck('name', 'id');

        $items = [];
        $errors = [];
        $warnings = [];

        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 2; 

            $sku = trim((string) ($row['item_code'] ?? ''));
            if ($sku === '') {
                $errors[] = ['row' => $rowNo, 'field' => 'item_code', 'error' => 'Terdapat baris kosong tanpa SKU. Pastikan SKU diisi.'];
                continue;
            }

            $variant = $variants->get(strtolower($sku));
            if (! $variant) {
                $errors[] = ['row' => $rowNo, 'field' => 'item_code', 'error' => "[SKU: {$sku}] SKU tidak terdaftar di sistem."];
                continue;
            }

            $deltaRaw = $row['delta_qty'] ?? null;
            $finalRaw = $row['final_qty'] ?? null;

            $hasDelta = $deltaRaw !== null && $deltaRaw !== '';
            $hasFinal = $finalRaw !== null && $finalRaw !== '';

            if (! $hasDelta && ! $hasFinal) {
                $errors[] = ['row' => $rowNo, 'field' => 'delta_qty|final_qty', 'error' => "[SKU: {$sku}] Mohon isi angka pada kolom delta_qty ATAU final_qty."];
                continue;
            }

            if ($hasDelta && $hasFinal) {
                $errors[] = ['row' => $rowNo, 'field' => 'delta_qty|final_qty', 'error' => "[SKU: {$sku}] Tidak bisa mengisi delta_qty dan final_qty bersamaan. Pilih salah satu."];
                continue;
            }

            $mode = $hasDelta ? StockAdjustmentRule::MODE_DELTA : StockAdjustmentRule::MODE_FINAL;
            $inputRaw = $mode === StockAdjustmentRule::MODE_DELTA ? $deltaRaw : $finalRaw;

            try {
                $inputValue = $this->stockAdjustmentRule->parseInteger(
                    $inputRaw,
                    $mode === StockAdjustmentRule::MODE_DELTA ? 'delta_qty' : 'final_qty',
                );
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'row' => $rowNo,
                    'field' => $mode === StockAdjustmentRule::MODE_DELTA ? 'delta_qty' : 'final_qty',
                    'error' => "[SKU: {$sku}] {$e->getMessage()}",
                ];
                continue;
            }

            $binCode = trim((string) ($row['bin_final_code'] ?? ''));
            $binId = null;
            $binResolved = null;

            if ($binCode !== '') {
                $bin = $bins->get($binCode);
                if (! $bin) {
                    $errors[] = ['row' => $rowNo, 'field' => 'bin_final_code', 'error' => "[SKU: {$sku}] Rak '{$binCode}' tidak ditemukan di lokasi ini."];
                    continue;
                }
                if ((bool) $bin->is_inbound) {
                    $errors[] = [
                        'row' => $rowNo,
                        'field' => 'bin_final_code',
                        'error' => "[SKU: {$sku}] Rak '{$binCode}' adalah bin inbound/DEFAULT dan tidak dapat dipakai untuk penyesuaian. Lakukan putaway ke rak final terlebih dahulu.",
                    ];
                    continue;
                }
                $binId = $bin->id;
                $binResolved = $bin->bin_final_code;
            } else {

                $primary = Inventory::where('item_id', $variant->id)
                    ->where('location_id', $locationId)
                    ->whereNotNull('bin_id')
                    ->where('on_hand', '>', 0)
                    ->with('bin:id,bin_final_code')
                    ->whereHas('bin', fn ($q) => $q->where('is_inbound', false))
                    ->orderBy('created_at')
                    ->first();

                if (! $primary) {
                    $primary = Inventory::where('item_id', $variant->id)
                        ->where('location_id', $locationId)
                        ->whereNotNull('bin_id')
                        ->with('bin:id,bin_final_code')
                        ->whereHas('bin', fn ($q) => $q->where('is_inbound', false))
                        ->orderBy('created_at')
                        ->first();
                }

                if (! $primary) {
                    $errors[] = ['row' => $rowNo, 'field' => 'bin_final_code', 'error' => "[SKU: {$sku}] Tidak ada stok pada rak final di gudang ini. Stok di DEFAULT wajib dipindahkan lewat putaway terlebih dahulu."];
                    continue;
                }
                $binId = $primary->bin_id;
                $binResolved = $primary->bin?->bin_final_code;
            }

            $inventory = Inventory::where('item_id', $variant->id)
                ->where('location_id', $locationId)
                ->where('bin_id', $binId)
                ->first();

            $systemQty = $inventory ? (int) $inventory->on_hand : 0;

            try {
                $calculation = $this->stockAdjustmentRule->calculate(
                    systemQty: $systemQty,
                    inputValue: $inputValue,
                    mode: $mode,
                );
            } catch (NegativeStockAdjustmentException|\InvalidArgumentException $e) {
                $errors[] = [
                    'row' => $rowNo,
                    'field' => $mode === StockAdjustmentRule::MODE_DELTA ? 'delta_qty' : 'final_qty',
                    'error' => "[SKU: {$sku}] {$e->getMessage()}",
                ];
                continue;
            }

            $hppRaw = $row['hpp'] ?? null;
            $unitCost = null;
            if ($hppRaw !== null && $hppRaw !== '') {
                $unitCost = (float) $hppRaw;
                if ($unitCost < 0) {
                    $errors[] = ['row' => $rowNo, 'field' => 'hpp', 'error' => "[SKU: {$sku}] Harga Pokok (HPP) tidak boleh kurang dari 0."];
                    continue;
                }
            }

            $noteText = trim((string) ($row['notes'] ?? ''));

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
                'actual_qty'    => $calculation->actualQty,
                'difference'    => $calculation->differenceQty,
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
            'actor_id'    => $actorId,
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

    public function getPreview(string $token, ?string $actorId = null): ?array
    {
        $preview = Cache::get(self::CACHE_PREFIX . $token);

        if (! is_array($preview)) {
            return null;
        }

        if (array_key_exists('actor_id', $preview) && $preview['actor_id'] !== $actorId) {
            return null;
        }

        return $preview;
    }

    public function forgetPreview(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX . $token);
    }

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

    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

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
            '5. Hasil stok tidak boleh minus; baris yang menghasilkan stok minus akan ditolak.',
            '6. Baca sheet "Tata Cara Pengisian" untuk detail tiap kolom.',
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

        $tata = $spreadsheet->createSheet();
        $tata->setTitle('Tata Cara Pengisian');
        $tataRows = [
            ['Nama Kolom', 'Wajib', 'Tipe', 'Contoh', 'Keterangan'],
            ['item_code', 'Ya', 'Text', 'SKU-12345', 'SKU varian yang mau disesuaikan'],
            ['bin_final_code', 'Opsional', 'Text', 'L1-B1-K1-R2', 'Kosongkan → sistem pakai rak utama otomatis'],
            ['delta_qty', 'Salah satu', 'Integer (+/-)', '10 atau -5', 'Selisih tambah/kurang dari on_hand sekarang; hasil akhirnya tidak boleh minus'],
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

        $tata->setCellValue('A10', 'PENTING: delta_qty DAN final_qty adalah "salah satu" — isi hanya salah satu per baris.');
        $tata->getStyle('A10')->getFont()->setBold(true)->getColor()->setRGB('B22222');
        $tata->mergeCells('A10:E10');

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

        $data->getStyle('A1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFD9B3');
        $data->getStyle('C1:D1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFD9B3');

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
