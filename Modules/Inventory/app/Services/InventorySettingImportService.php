<?php

namespace Modules\Inventory\Services;

use App\Traits\StockLockable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinOccupancyGuard;
use Modules\Warehouse\Services\SkuHomeBinGuard;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class InventorySettingImportService
{
    use StockLockable;

    public const CACHE_PREFIX = 'inventory-setting-import:';
    public const CACHE_TTL_MINUTES = 30;
    public const MAX_ROWS = 5000;

    public const TYPE_SAFE_STOCK = 'safe-stock';
    public const TYPE_MIN_STOCK = 'min-stock';
    public const TYPE_RACK = 'rack-allocation';

    private const SKU_ALIASES = ['kode_produk', 'item_code', 'sku'];
    private const SAFE_ALIASES = ['batas_stok_aman', 'safe_stock'];
    private const MIN_ALIASES = ['batas_stok_menipis', 'min_stock'];
    private const LOCATION_ALIASES = ['location_name', 'lokasi', 'nama_lokasi', 'nama lokasi'];
    private const BIN_ALIASES = ['bin_final_code', 'rak', 'kode_rak', 'kode rak', 'kode_final_rak', 'kode final rak'];

    public function __construct(
        protected InventoryService $inventoryService,
    ) {}

    public function preview(UploadedFile $file, string $type): array
    {
        $rows = $this->readRows($file);

        if (empty($rows)) {
            throw new \Exception('File kosong atau format kolom tidak dikenali.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new \Exception('Maksimal ' . self::MAX_ROWS . ' baris. File berisi ' . count($rows) . ' baris.');
        }

        return match ($type) {
            self::TYPE_SAFE_STOCK => $this->previewThreshold($rows, 'safe_stock', self::SAFE_ALIASES, false),
            self::TYPE_MIN_STOCK  => $this->previewThreshold($rows, 'min_stock', self::MIN_ALIASES, true),
            self::TYPE_RACK       => $this->previewRack($rows),
            default               => throw new \Exception('Jenis import tidak dikenali.'),
        };
    }

    protected function previewThreshold(array $rows, string $column, array $valueAliases, bool $mustBePositive): array
    {
        $skus = collect($rows)->map(fn ($r) => trim((string) $this->pick($r, self::SKU_ALIASES)))
            ->filter()->unique()->values()->all();

        $variants = ProductVariant::whereIn('sku', $skus)
            ->get(['id', 'sku', 'product_id', $column])
            ->keyBy(fn ($v) => strtolower($v->sku));

        $names = Product::whereIn('id', $variants->pluck('product_id')->filter()->unique())->pluck('name', 'id');

        $items = [];
        $errors = 0;

        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 2;
            $sku = trim((string) $this->pick($row, self::SKU_ALIASES));
            $rawValue = $this->pick($row, $valueAliases);

            if ($sku === '') {
                continue;
            }

            $variant = $variants->get(strtolower($sku));
            if (! $variant) {
                $items[] = $this->thresholdRow($rowNo, $sku, null, null, null, 'error', 'SKU tidak terdaftar.');
                $errors++;
                continue;
            }

            if ($rawValue === null || $rawValue === '' || ! is_numeric($rawValue)) {
                $items[] = $this->thresholdRow($rowNo, $sku, $names[$variant->product_id] ?? '', (int) $variant->{$column}, null, 'error', 'Nilai harus berupa angka.');
                $errors++;
                continue;
            }

            $value = (int) $rawValue;
            if ($value < 0 || ($mustBePositive && $value <= 0)) {
                $items[] = $this->thresholdRow(
                    $rowNo,
                    $sku,
                    $names[$variant->product_id] ?? '',
                    (int) $variant->{$column},
                    $value,
                    'error',
                    $mustBePositive ? 'Nilai harus lebih besar dari 0.' : 'Nilai tidak boleh kurang dari 0.',
                );
                $errors++;
                continue;
            }

            $items[] = $this->thresholdRow(
                $rowNo,
                $sku,
                $names[$variant->product_id] ?? '',
                (int) $variant->{$column},
                $value,
                'update',
                null,
                $variant->id,
            );
        }

        $valid = count($items) - $errors;
        $summary = ['total_rows' => count($rows), 'valid' => $valid, 'error' => $errors];

        return $this->store([
            'type'    => $column === 'safe_stock' ? self::TYPE_SAFE_STOCK : self::TYPE_MIN_STOCK,
            'column'  => $column,
            'items'   => $items,
            'summary' => $summary,
        ]) + ['items' => $items, 'summary' => $summary];
    }

    protected function thresholdRow(int $rowNo, string $sku, ?string $name, ?int $current, ?int $new, string $status, ?string $message, ?string $itemId = null): array
    {
        return [
            'row_no'       => $rowNo,
            'sku'          => $sku,
            'product_name' => $name,
            'current'      => $current,
            'new'          => $new,
            'status'       => $status,
            'message'      => $message,
            'item_id'      => $itemId,
        ];
    }

    protected function previewRack(array $rows): array
    {
        $skus = collect($rows)->map(fn ($r) => trim((string) $this->pick($r, self::SKU_ALIASES)))->filter()->unique()->values()->all();
        $variants = ProductVariant::whereIn('sku', $skus)->get(['id', 'sku', 'product_id'])->keyBy(fn ($v) => strtolower($v->sku));
        $names = Product::whereIn('id', $variants->pluck('product_id')->filter()->unique())->pluck('name', 'id');

        $locations = Location::all(['id', 'location_name', 'location_code']);
        $locByName = $locations->keyBy(fn ($l) => strtolower(trim($l->location_name)));
        $locByCode = $locations->keyBy(fn ($l) => strtolower(trim($l->location_code)));

        $homeGuard = app(SkuHomeBinGuard::class);
        $binGuard = app(BinOccupancyGuard::class);
        $ruleService = app(\Modules\Warehouse\Services\BinMultiSkuRuleService::class);

        $items = [];
        $manualMoves = [];
        $counts = ['place' => 0, 'manual_move' => 0, 'already' => 0, 'error' => 0];

        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 2;
            $sku = trim((string) $this->pick($row, self::SKU_ALIASES));
            $locName = trim((string) $this->pick($row, self::LOCATION_ALIASES));
            $binCode = trim((string) $this->pick($row, self::BIN_ALIASES));

            if ($sku === '' && $locName === '' && $binCode === '') {
                continue;
            }

            $push = function (string $status, string $message, array $extra = []) use (&$items, &$counts, $rowNo, $sku, $locName, $binCode) {
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $items[] = array_merge([
                    'row_no'         => $rowNo,
                    'sku'            => $sku,
                    'location_name'  => $locName,
                    'bin_final_code' => $binCode,
                    'status'         => $status,
                    'message'        => $message,
                ], $extra);
            };

            $variant = $variants->get(strtolower($sku));
            if (! $variant) {
                $push('error', 'SKU tidak terdaftar.');
                continue;
            }

            $location = $locByName->get(strtolower($locName)) ?? $locByCode->get(strtolower($locName));
            if (! $location) {
                $push('error', "Lokasi \"{$locName}\" tidak ditemukan.");
                continue;
            }

            $bin = LocationBin::where('location_id', $location->id)->where('bin_final_code', $binCode)->first(['id', 'location_id', 'bin_final_code', 'is_inbound']);
            if (! $bin) {
                $push('error', "Rak \"{$binCode}\" tidak ada di lokasi ini.");
                continue;
            }
            if ($bin->is_inbound) {
                $push('error', 'Rak inbound tidak bisa dijadikan alokasi.');
                continue;
            }

            $homeBinId = $homeGuard->currentHomeBinId($location->id, $variant->id);

            if ($homeBinId === $bin->id) {
                $push('already', 'SKU sudah berada di rak ini.', ['product_name' => $names[$variant->product_id] ?? '']);
                continue;
            }

            if ($homeBinId !== null) {
                $currentCode = LocationBin::whereKey($homeBinId)->value('bin_final_code');
                $push('manual_move', "Stok sudah ada di rak {$currentCode}. Pindahkan ke {$binCode} lewat Pindah Bin secara manual.", [
                    'product_name' => $names[$variant->product_id] ?? '',
                    'current_bin'  => $currentCode,
                ]);
                $manualMoves[] = [
                    'sku'            => $sku,
                    'location_name'  => $location->location_name,
                    'current_bin'    => $currentCode,
                    'target_bin'     => $binCode,
                ];
                continue;
            }

            if ($location->enforcesStrictBinSku() && ! $ruleService->allowsMultiSku($bin)) {
                $occupant = $binGuard->currentOccupantItemId($bin->id);
                if ($occupant !== null && $occupant !== $variant->id) {
                    $push('error', 'Rak sudah berisi SKU lain (gudang kecil = 1 rak 1 SKU).');
                    continue;
                }
            }

            $hasPending = Inventory::query()->pendingPlacement()
                ->where('inventories.location_id', $location->id)
                ->where('inventories.item_id', $variant->id)
                ->where('inventories.on_hand', '>', 0)
                ->exists();

            if (! $hasPending) {
                $push('error', 'Tidak ada stok pending untuk ditempatkan (SKU belum diterima di gudang ini).');
                continue;
            }

            $push('place', 'Akan ditempatkan ke rak ini saat disimpan.', [
                'product_name' => $names[$variant->product_id] ?? '',
                'item_id'      => $variant->id,
                'location_id'  => $location->id,
                'bin_id'       => $bin->id,
            ]);
        }

        $summary = array_merge(['total_rows' => count($rows)], $counts);

        return $this->store([
            'type'         => self::TYPE_RACK,
            'items'        => $items,
            'summary'      => $summary,
            'manual_moves' => $manualMoves,
        ]) + ['items' => $items, 'summary' => $summary, 'manual_moves' => $manualMoves];
    }

    public function confirm(string $token, ?string $userId): array
    {
        $preview = $this->getPreview($token);
        if (! $preview) {
            throw new \Exception('Preview token kedaluwarsa. Silakan unggah ulang.');
        }

        $applied = $preview['type'] === self::TYPE_RACK
            ? $this->applyRack($preview, $userId)
            : $this->applyThreshold($preview);

        $this->forgetPreview($token);

        return $applied;
    }

    protected function applyThreshold(array $preview): array
    {
        $column = $preview['column'];
        $updates = collect($preview['items'])->where('status', 'update');

        $byValue = $updates->groupBy(fn ($it) => (int) $it['new']);

        DB::transaction(function () use ($byValue, $column) {
            foreach ($byValue as $value => $rows) {
                ProductVariant::whereKey($rows->pluck('item_id')->all())
                    ->update([$column => (int) $value]);
            }
        });

        return ['applied' => $updates->count(), 'skipped' => 0];
    }

    protected function applyRack(array $preview, ?string $userId): array
    {
        $placeRows = collect($preview['items'])->where('status', 'place');
        $applied = 0;

        foreach ($placeRows as $it) {
            $this->placeSkuToBin($it['location_id'], $it['bin_id'], $it['item_id'], (string) ($userId ?? ''));
            $applied++;
        }

        return [
            'applied'      => $applied,
            'manual_moves' => count($preview['manual_moves'] ?? []),
        ];
    }

    protected function placeSkuToBin(string $locationId, string $binId, string $itemId, string $userId): void
    {
        app(SkuHomeBinGuard::class)->assertSkuFitsBin($locationId, $itemId, $binId);
        app(BinOccupancyGuard::class)->assertBinFitsSku($binId, $itemId);

        $this->withStockLock($itemId, $locationId, function () use ($locationId, $binId, $itemId, $userId) {
            DB::transaction(function () use ($locationId, $binId, $itemId, $userId) {
                $sources = Inventory::query()->pendingPlacement()
                    ->where('inventories.location_id', $locationId)
                    ->where('inventories.item_id', $itemId)
                    ->where('inventories.on_hand', '>', 0)
                    ->lockForUpdate()
                    ->get();

                foreach ($sources as $row) {
                    $qty = (int) $row->on_hand;
                    if ($qty <= 0) {
                        continue;
                    }
                    $this->inventoryService->putaway([
                        'item_id'            => $itemId,
                        'location_id'        => $locationId,
                        'source_bin_id'      => $row->bin_id,
                        'destination_bin_id' => $binId,
                        'qty'                => $qty,
                        'batch_no'           => $row->batch_no ?? '',
                        'serial_no'          => $row->serial_no ?? '',
                        'created_by'         => "user:{$userId}",
                    ]);
                }
            });
        });
    }

    protected function store(array $payload): array
    {
        $token = 'imp_' . Str::uuid()->toString();
        Cache::put(self::CACHE_PREFIX . $token, $payload, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return ['token' => $token];
    }

    public function getPreview(string $token): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $token);
    }

    public function forgetPreview(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX . $token);
    }

    protected function pick(array $assoc, array $aliases)
    {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $assoc) && $assoc[$alias] !== null && $assoc[$alias] !== '') {
                return $assoc[$alias];
            }
        }

        return null;
    }

    protected function norm($h): string
    {
        return strtolower(trim((string) $h));
    }

    protected function readRows(UploadedFile $file): array
    {
        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        if (in_array($ext, ['csv', 'txt'], true)) {
            return $this->readCsv($file);
        }

        return $this->readXlsx($file);
    }

    protected function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        $header = null;

        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($header === null) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($line[0] ?? ''));
                $header = array_map(fn ($h) => $this->norm($h), $line);
                continue;
            }
            $rows = $this->appendRow($rows, $header, $line);
        }
        fclose($handle);

        return $rows;
    }

    protected function readXlsx(UploadedFile $file): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());

        $detector = array_merge(self::SKU_ALIASES);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $data = $sheet->toArray(null, true, true, false);
            if (empty($data)) {
                continue;
            }
            $header = array_map(fn ($h) => $this->norm($h), $data[0]);
            if (count(array_intersect($detector, $header)) === 0) {
                continue;
            }

            array_shift($data);
            $rows = [];
            foreach ($data as $line) {
                $rows = $this->appendRow($rows, $header, $line);
            }

            return $rows;
        }

        return [];
    }

    protected function appendRow(array $rows, array $header, array $line): array
    {
        $nonEmpty = array_filter($line, fn ($v) => $v !== null && trim((string) $v) !== '');
        if (empty($nonEmpty)) {
            return $rows;
        }

        $assoc = [];
        foreach ($header as $i => $col) {
            if ($col === '') {
                continue;
            }
            $assoc[$col] = $line[$i] ?? null;
        }
        $rows[] = $assoc;

        return $rows;
    }

    public function generateTemplate(string $type): Spreadsheet
    {
        [$title, $headers, $examples] = match ($type) {
            self::TYPE_SAFE_STOCK => ['Batas Stok Aman', ['Kode_Produk', 'Batas_Stok_Aman'], [['SKU-CONTOH-1', 100]]],
            self::TYPE_MIN_STOCK  => ['Batas Stok Menipis', ['Kode_Produk', 'Batas_Stok_Menipis'], [['SKU-CONTOH-1', 50]]],
            self::TYPE_RACK       => ['Alokasi Rak', ['SKU', 'Lokasi', 'Rak'], [['SKU-CONTOH-1', 'Gudang Kecil', 'O-C3-K1-X4']]],
            default               => throw new \Exception('Jenis template tidak dikenali.'),
        };

        $spreadsheet = new Spreadsheet();

        $instr = $spreadsheet->getActiveSheet();
        $instr->setTitle('Instruksi');
        $instr->setCellValue('A1', "Import {$title} — Cilupbah");
        $instr->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $lines = [
            '',
            '1. Isi data pada sheet "Pengisian Data".',
            '2. Kolom wajib diisi semua.',
            '3. Simpan sebagai .csv (delimiter koma) lalu unggah.',
        ];
        if ($type === self::TYPE_RACK) {
            $lines[] = '4. Alokasi rak menempatkan stok yang belum punya rak. SKU yang sudah punya stok di rak lain akan ditandai untuk dipindah manual (Pindah Bin).';
        }
        $r = 2;
        foreach ($lines as $line) {
            $instr->setCellValue("A{$r}", $line);
            $r++;
        }
        $instr->getColumnDimension('A')->setWidth(100);

        $data = $spreadsheet->createSheet();
        $data->setTitle('Pengisian Data');
        foreach ($headers as $j => $h) {
            $col = chr(65 + $j);
            $data->setCellValue("{$col}1", $h);
        }
        $lastCol = chr(65 + count($headers) - 1);
        $data->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $data->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFD9B3');
        foreach ($examples as $i => $row) {
            $rowNum = $i + 2;
            foreach ($row as $j => $val) {
                $col = chr(65 + $j);
                $data->setCellValue("{$col}{$rowNum}", $val);
            }
        }
        foreach (range('A', $lastCol) as $col) {
            $data->getColumnDimension($col)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(1);

        return $spreadsheet;
    }
}
