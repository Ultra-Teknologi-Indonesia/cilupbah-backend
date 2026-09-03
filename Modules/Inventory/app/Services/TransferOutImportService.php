<?php

namespace Modules\Inventory\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class TransferOutImportService
{
    public const CACHE_PREFIX = 'transfer-out-import:';
    public const CACHE_TTL_MINUTES = 30;
    public const MAX_ROWS = 1000;
    public const DATA_SHEET_NAME = 'Pengisian Data Transfer';

    public function __construct(
        protected InventoryService $inventoryService,
        protected ImpexActivityService $activityService,
    ) {}

    public function confirmWithActivity(string $token, string $createdBy, ?string $userId = null): array
    {
        $activity = $this->activityService->record(
            ImpexActivity::DIRECTION_IMPORT,
            'Import Transfer Keluar',
            $userId,
        );

        try {
            $result = $this->confirm($token, $createdBy);

            if ($result['failed'] > 0) {
                $this->activityService->addDetails($activity, array_map(
                    static function (string $error): array {
                        $ref = trim(Str::before($error, ':'));
                        $description = trim(Str::after($error, ':')) ?: $error;

                        return [
                            'reference_id' => $ref ?: 'Error',
                            'description' => $description,
                        ];
                    },
                    $result['errors'],
                ));

                if ($result['created'] === 0) {
                    $this->activityService->markFailed($activity, implode('; ', $result['errors']));
                } else {
                    $this->activityService->markSuccess($activity);
                    $this->activityService->setMessage(
                        $activity,
                        "{$result['created']} transfer dibuat, {$result['failed']} gagal.",
                    );
                }
            } else {
                $this->activityService->markSuccess($activity);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->activityService->markFailed($activity, $e->getMessage());

            throw $e;
        }
    }

    public function preview(UploadedFile $file): array
    {
        $rows = $this->readDataRows($file);

        if (empty($rows)) {
            throw new \Exception('Sheet "' . self::DATA_SHEET_NAME . '" kosong atau tidak ditemukan.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new \Exception('Maksimal ' . self::MAX_ROWS . ' baris. File berisi ' . count($rows) . ' baris.');
        }

        $errors = [];
        $warnings = [];

        $groups = $this->groupRows($rows, $errors);

        [$variants, $productNames] = $this->loadVariants($groups);
        $locations = $this->loadLocations($groups);
        $bins = $this->loadBins($groups, $locations);

        $transfers = [];
        $payloads = [];
        $usedNumbers = [];

        foreach ($groups as $group) {
            $headerRowNo = $group['header_row_no'];
            $groupErrors = [];

            $refRaw = trim((string) ($group['ref_no'] ?? ''));
            $isAuto = $refRaw === '' || strtolower($refRaw) === '[auto]';
            $transferNumber = $isAuto ? null : $refRaw;

            if ($transferNumber !== null) {
                $key = strtolower($transferNumber);
                if (isset($usedNumbers[$key])) {
                    $groupErrors[] = "Baris {$headerRowNo}: No Transfer \"{$transferNumber}\" duplikat di dalam file.";
                } elseif (InventoryTransfer::where('transfer_number', $transferNumber)->exists()) {
                    $groupErrors[] = "Baris {$headerRowNo}: No Transfer \"{$transferNumber}\" sudah digunakan di sistem.";
                }
                $usedNumbers[$key] = true;
            }

            $sourceName = trim((string) ($group['source_location'] ?? ''));
            $destName = trim((string) ($group['destination_location'] ?? ''));

            $sourceId = $sourceName !== '' ? ($locations[strtolower($sourceName)] ?? null) : null;
            $destId = $destName !== '' ? ($locations[strtolower($destName)] ?? null) : null;

            if ($sourceName === '') {
                $groupErrors[] = "Baris {$headerRowNo}: Lokasi asal (source_location) wajib diisi.";
            } elseif (! $sourceId) {
                $groupErrors[] = "Baris {$headerRowNo}: Lokasi asal \"{$sourceName}\" tidak terdaftar.";
            }

            if ($destName === '') {
                $groupErrors[] = "Baris {$headerRowNo}: Lokasi tujuan (destination_location) wajib diisi.";
            } elseif (! $destId) {
                $groupErrors[] = "Baris {$headerRowNo}: Lokasi tujuan \"{$destName}\" tidak terdaftar.";
            }

            $txDate = trim((string) ($group['transaction_date'] ?? ''));
            if ($txDate !== '' && ! $this->isValidDate($txDate)) {
                $warnings[] = ['row' => $headerRowNo, 'field' => 'transaction_date', 'warning' => "Format tanggal \"{$txDate}\" tidak dikenali, nilai diabaikan."];
            }

            $items = [];
            foreach ($group['items'] as $line) {
                $rowNo = $line['row_no'];

                $sku = trim((string) ($line['item_code'] ?? ''));
                if ($sku === '') {
                    $groupErrors[] = "Baris {$rowNo}: SKU (item_code) wajib diisi.";
                    continue;
                }

                $variant = $variants->get(strtolower($sku));
                if (! $variant) {
                    $groupErrors[] = "Baris {$rowNo}: SKU \"{$sku}\" tidak terdaftar di sistem.";
                    continue;
                }

                $qtyRaw = $line['qty_in_base'] ?? null;
                if ($qtyRaw === null || $qtyRaw === '' || ! is_numeric($qtyRaw) || (int) $qtyRaw < 1) {
                    $groupErrors[] = "Baris {$rowNo}: Jumlah (qty_in_base) harus bilangan minimal 1.";
                    continue;
                }
                $qty = (int) $qtyRaw;

                $binCode = trim((string) ($line['kode_rak'] ?? ''));
                if ($binCode === '') {
                    $groupErrors[] = "Baris {$rowNo}: Kode rak (kode_rak) wajib diisi.";
                    continue;
                }

                $binId = null;
                if ($sourceId) {
                    $binId = $bins[$sourceId . '|' . $binCode] ?? null;
                    if (! $binId) {
                        $groupErrors[] = "Baris {$rowNo}: Rak \"{$binCode}\" tidak ditemukan di lokasi asal \"{$sourceName}\".";
                        continue;
                    }

                    $available = $this->availableAtBin($variant->id, $sourceId, $binId);
                    if ($available < $qty) {
                        $warnings[] = ['row' => $rowNo, 'field' => 'qty_in_base', 'warning' => "SKU \"{$sku}\" di rak \"{$binCode}\": tersedia {$available}, diminta {$qty}."];
                    }
                }

                $items[] = [
                    'row_no'       => $rowNo,
                    'item_id'      => $variant->id,
                    'sku'          => $variant->sku,
                    'product_name' => $productNames[$variant->product_id] ?? '',
                    'qty'          => $qty,
                    'source_bin_id' => $binId,
                    'bin_code'     => $binCode,
                    'batch_no'     => trim((string) ($line['batch_no'] ?? '')),
                    'serial_no'    => trim((string) ($line['serial_no'] ?? '')),
                ];
            }

            if (empty($items) && empty($groupErrors)) {
                $groupErrors[] = "Baris {$headerRowNo}: Dokumen transfer tidak memiliki item.";
            }

            $status = empty($groupErrors) ? 'ready' : 'error';

            $transfers[] = [
                'ref_no'               => $isAuto ? '(otomatis)' : $transferNumber,
                'transaction_date'     => $txDate !== '' ? $txDate : null,
                'source_location'      => $sourceName,
                'destination_location' => $destName,
                'notes'                => trim((string) ($group['note'] ?? '')) ?: null,
                'item_count'           => count($group['items']),
                'items'                => array_map(fn ($it) => [
                    'sku'          => $it['sku'],
                    'product_name' => $it['product_name'],
                    'qty'          => $it['qty'],
                    'kode_rak'     => $it['bin_code'],
                ], $items),
                'status'               => $status,
                'errors'               => array_values($groupErrors),
            ];

            foreach ($groupErrors as $msg) {
                $errors[] = ['row' => $headerRowNo, 'field' => 'transfer', 'error' => $msg];
            }

            if ($status === 'ready') {
                $payloads[] = [
                    'transfer_number'         => $transferNumber,
                    'transaction_date'        => $txDate !== '' ? $this->parseDate($txDate) : null,
                    'source_location_id'      => $sourceId,
                    'destination_location_id' => $destId,
                    'notes'                   => trim((string) ($group['note'] ?? '')) ?: null,
                    'items'                   => array_map(fn ($it) => [
                        'item_id'       => $it['item_id'],
                        'qty'           => $it['qty'],
                        'source_bin_id' => $it['source_bin_id'],
                        'batch_no'      => $it['batch_no'],
                        'serial_no'     => $it['serial_no'],
                    ], $items),
                ];
            }
        }

        $token = 'imp_' . Str::uuid()->toString();

        $summary = [
            'total_rows'  => count($rows),
            'total_docs'  => count($transfers),
            'valid_docs'  => count($payloads),
            'errors'      => count($errors),
            'warnings'    => count($warnings),
        ];

        Cache::put(self::CACHE_PREFIX . $token, [
            'payloads' => $payloads,
            'summary'  => $summary,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'token'     => $token,
            'transfers' => $transfers,
            'errors'    => $errors,
            'warnings'  => $warnings,
            'summary'   => $summary,
        ];
    }

    public function getPreview(string $token): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $token);
    }

    public function forgetPreview(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX . $token);
    }

    public function confirm(string $token, string $createdBy): array
    {
        $preview = $this->getPreview($token);

        if (! $preview) {
            throw new \Exception('Preview token kadaluarsa atau tidak ditemukan. Silakan upload ulang.');
        }

        $payloads = $preview['payloads'] ?? [];
        if (empty($payloads)) {
            throw new \Exception('Tidak ada dokumen transfer valid untuk di-import.');
        }

        $created = 0;
        $failed = 0;
        $transferNumbers = [];
        $errors = [];

        foreach ($payloads as $idx => $payload) {
            $label = $payload['transfer_number'] ?? '(otomatis #' . ($idx + 1) . ')';
            try {
                DB::transaction(function () use ($payload, $createdBy, &$transferNumbers) {
                    $transfer = $this->inventoryService->createDraft([
                        'transfer_number'         => $payload['transfer_number'],
                        'transaction_date'        => $payload['transaction_date'] ?? null,
                        'source_location_id'      => $payload['source_location_id'],
                        'destination_location_id' => $payload['destination_location_id'],
                        'notes'                   => $payload['notes'],
                        'created_by'              => $createdBy,
                    ]);

                    foreach ($payload['items'] as $item) {
                        $this->inventoryService->addDraftItem($transfer->id, [
                            'item_id'       => $item['item_id'],
                            'qty'           => $item['qty'],
                            'source_bin_id' => $item['source_bin_id'],
                            'batch_no'      => $item['batch_no'],
                            'serial_no'     => $item['serial_no'],
                        ]);
                    }

                    $transferNumbers[] = $transfer->transfer_number;
                });

                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Transfer {$label}: " . $e->getMessage();
            }
        }

        $this->forgetPreview($token);

        return [
            'created'          => $created,
            'failed'           => $failed,
            'transfer_numbers' => $transferNumbers,
            'errors'           => $errors,
        ];
    }

    protected function groupRows(array $rows, array &$errors): array
    {
        $groups = [];
        $current = null;

        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 2;
            $ref = trim((string) ($row['ref_no'] ?? ''));

            if ($ref !== '') {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = [
                    'header_row_no'        => $rowNo,
                    'ref_no'               => $ref,
                    'transaction_date'     => $row['transaction_date'] ?? '',
                    'note'                 => $row['note'] ?? '',
                    'source_location'      => $row['source_location'] ?? '',
                    'destination_location' => $row['destination_location'] ?? '',
                    'items'                => [],
                ];
            } elseif ($current === null) {
                $errors[] = ['row' => $rowNo, 'field' => 'ref_no', 'error' => "Baris {$rowNo}: baris pertama tanpa No Transfer (ref_no). Isi No Transfer atau [auto]."];
                continue;
            }

            $current['items'][] = [
                'row_no'      => $rowNo,
                'item_code'   => $row['item_code'] ?? '',
                'serial_no'   => $row['serial_no'] ?? '',
                'batch_no'    => $row['batch_no'] ?? '',
                'qty_in_base' => $row['qty_in_base'] ?? '',
                'kode_rak'    => $row['kode_rak'] ?? '',
            ];
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    protected function loadVariants(array $groups): array
    {
        $skus = [];
        foreach ($groups as $g) {
            foreach ($g['items'] as $line) {
                $sku = trim((string) ($line['item_code'] ?? ''));
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
        }
        $skus = array_values(array_unique($skus));

        $variants = ProductVariant::whereIn('sku', $skus)
            ->get(['id', 'sku', 'product_id'])
            ->keyBy(fn ($v) => strtolower($v->sku));

        $productNames = Product::whereIn('id', $variants->pluck('product_id')->filter()->unique())
            ->pluck('name', 'id');

        return [$variants, $productNames];
    }

    protected function loadLocations(array $groups): array
    {
        $names = [];
        foreach ($groups as $g) {
            foreach (['source_location', 'destination_location'] as $key) {
                $val = trim((string) ($g[$key] ?? ''));
                if ($val !== '') {
                    $names[] = $val;
                }
            }
        }
        $names = array_values(array_unique($names));

        $map = [];
        Location::get(['id', 'location_name', 'location_code'])
            ->each(function ($loc) use (&$map) {
                $map[strtolower((string) $loc->location_name)] = $loc->id;
                if ($loc->location_code) {
                    $map[strtolower((string) $loc->location_code)] = $loc->id;
                }
            });

        return $map;
    }

    protected function loadBins(array $groups, array $locations): array
    {
        $pairs = [];
        $codes = [];
        $locIds = [];

        foreach ($groups as $g) {
            $srcName = trim((string) ($g['source_location'] ?? ''));
            $srcId = $srcName !== '' ? ($locations[strtolower($srcName)] ?? null) : null;
            if (! $srcId) {
                continue;
            }
            foreach ($g['items'] as $line) {
                $code = trim((string) ($line['kode_rak'] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                    $locIds[] = $srcId;
                    $pairs[$srcId . '|' . $code] = true;
                }
            }
        }

        if (empty($pairs)) {
            return [];
        }

        $map = [];
        LocationBin::whereIn('location_id', array_values(array_unique($locIds)))
            ->whereIn('bin_final_code', array_values(array_unique($codes)))
            ->get(['id', 'location_id', 'bin_final_code'])
            ->each(function ($bin) use (&$map) {
                $map[$bin->location_id . '|' . $bin->bin_final_code] = $bin->id;
            });

        return $map;
    }

    protected function availableAtBin(string $itemId, string $locationId, string $binId): int
    {
        $inventory = Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->first();

        if (! $inventory) {
            return 0;
        }

        return (int) $inventory->on_hand - (int) $inventory->on_order;
    }

    protected function isValidDate(string $value): bool
    {
        foreach (['j/n/Y G:i', 'j/n/Y H:i', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt !== false) {
                return true;
            }
        }

        return strtotime($value) !== false;
    }

    protected function parseDate(string $value): ?string
    {
        foreach (['j/n/Y G:i', 'j/n/Y H:i', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $time = strtotime($value);
        return $time !== false ? date('Y-m-d H:i:s', $time) : null;
    }

    protected function readDataRows(UploadedFile $file): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());

        $sheet = $spreadsheet->sheetNameExists(self::DATA_SHEET_NAME)
            ? $spreadsheet->getSheetByName(self::DATA_SHEET_NAME)
            : $spreadsheet->getSheet($spreadsheet->getSheetCount() - 1);

        $data = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            return [];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($data));

        $rows = [];
        foreach ($data as $row) {
            $nonEmpty = array_filter($row, fn ($v) => $v !== null && $v !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            $assoc = [];
            foreach ($header as $i => $col) {
                if ($col === '') {
                    continue;
                }
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
        $instr->setCellValue('A1', 'Import Transfer Keluar Cilupbah');
        $instr->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $instrLines = [
            '',
            '1. Buka sheet "' . self::DATA_SHEET_NAME . '" untuk mengisi data transfer keluar.',
            '2. Satu dokumen transfer bisa berisi banyak item.',
            '3. Isi No Transfer (ref_no) hanya di baris PERTAMA tiap dokumen. Baris item berikutnya biarkan ref_no KOSONG.',
            '4. Isi [auto] pada ref_no bila ingin nomor transfer dibuat otomatis oleh sistem.',
            '5. item_code (SKU), source_location, destination_location, qty_in_base, dan kode_rak WAJIB diisi.',
            '6. kode_rak harus terdaftar di lokasi asal (source_location).',
            '7. Baca sheet "Tata Cara Pengisian" untuk detail tiap kolom.',
            '8. Simpan sebagai .xlsx lalu upload lewat tombol Import di halaman Transfer Keluar.',
            '',
            'Catatan: hasil import muncul sebagai DRAFT di tab "Baru Dibuat", siap dicetak Surat Jalan.',
        ];
        $r = 2;
        foreach ($instrLines as $line) {
            $instr->setCellValue("A{$r}", $line);
            $r++;
        }
        $instr->getColumnDimension('A')->setWidth(100);

        $tata = $spreadsheet->createSheet();
        $tata->setTitle('Tata Cara Pengisian');
        $tataRows = [
            ['Nama Kolom', 'Wajib', 'Nilai Yang Diterima', 'Contoh', 'Keterangan'],
            ['ref_no', 'Wajib (baris pertama)', 'Bebas, atau [auto]', 'TFO-0000001 / [auto]', 'No transfer. Kosongkan di baris item lanjutan dokumen yang sama.'],
            ['transaction_date', 'Opsional', 'D/MM/YYYY hh:mm', '29/11/2018 16:55', 'Tanggal transfer (informasi saja, tidak disimpan).'],
            ['note', 'Opsional', 'Bebas', 'Transfer ke lokasi pusat', 'Catatan dokumen transfer.'],
            ['source_location', 'Wajib', 'Nama/kode lokasi terdaftar', 'Pusat', 'Lokasi asal transfer (stok dipotong dari sini).'],
            ['destination_location', 'Wajib', 'Nama/kode lokasi terdaftar', 'Jakarta', 'Lokasi tujuan transfer.'],
            ['item_code', 'Wajib', 'SKU terdaftar', 'BJ-0001', 'Kode SKU varian barang.'],
            ['serial_no', 'Opsional', 'Bebas', 'SR001', 'Nomor serial barang.'],
            ['batch_no', 'Opsional', 'Bebas', 'BT001', 'Nomor batch barang.'],
            ['qty_in_base', 'Wajib', 'Bilangan >= 1', '2', 'Jumlah barang yang ditransfer.'],
            ['kode_rak', 'Wajib', 'Kode rak di lokasi asal', 'L1-B1-K1-R1', 'Rak asal tempat stok diambil.'],
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
        foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
            $tata->getColumnDimension($col)->setAutoSize(true);
        }

        $data = $spreadsheet->createSheet();
        $data->setTitle(self::DATA_SHEET_NAME);
        $headers = ['ref_no', 'transaction_date', 'note', 'source_location', 'destination_location', 'item_code', 'serial_no', 'batch_no', 'qty_in_base', 'kode_rak'];
        foreach ($headers as $j => $h) {
            $col = $this->columnLetter($j);
            $data->setCellValue("{$col}1", $h);
        }
        $lastCol = $this->columnLetter(count($headers) - 1);
        $data->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $data->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFF2CC');

        foreach (['A1', 'D1', 'E1', 'F1', 'I1', 'J1'] as $reqCell) {
            $data->getStyle($reqCell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFD9B3');
        }

        $examples = [
            ['[auto]', '29/11/2018 14:22', 'Segera terima sebelum 1 Januari', 'Pusat', 'Jakarta', 'BJ-0001', '', '', 2, 'L1-B1-K1-R1'],
            ['', '29/11/2018 14:22', 'Segera terima sebelum 1 Januari', 'Pusat', 'Jakarta', 'BJ-0002', '', '', 2, 'L1-B1-K1-R1'],
            ['TFO-00002', '29/11/2018 17:35', '', 'Bandung', 'Bekasi', 'BJ-0003', '', '', 2, 'B-1-2-3'],
            ['', '29/11/2018 17:35', '', 'Bandung', 'Bekasi', 'BJ-0004', '', '', 2, 'A-A-A-B'],
        ];
        foreach ($examples as $i => $row) {
            $rowNum = $i + 2;
            foreach ($row as $j => $val) {
                $col = $this->columnLetter($j);
                $data->setCellValue("{$col}{$rowNum}", $val);
            }
        }
        foreach (range('A', $lastCol) as $col) {
            $data->getColumnDimension($col)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(2);

        return $spreadsheet;
    }

    protected function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
    }
}
