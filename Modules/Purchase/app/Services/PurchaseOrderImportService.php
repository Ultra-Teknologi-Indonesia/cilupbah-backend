<?php

namespace Modules\Purchase\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Supplier\Models\Contact;
use Modules\Tax\Models\Tax;
use Modules\Warehouse\Models\Location;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PurchaseOrderImportService
{
    public const CACHE_PREFIX = 'po-import:';
    public const CACHE_TTL_MINUTES = 30;
    public const MAX_ROWS = 2000;
    public const DATA_SHEET_NAME = 'Pengisian Data Pembelian';

    private const COLOR_REQUIRED = 'F4B183';
    private const COLOR_OPTIONAL = 'FFE699';
    private const COLOR_HEADER = '4472C4';

    public function __construct(
        protected PurchaseOrderService $poService,
        protected PurchaseOrderRepository $poRepository,
    ) {}

    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        // 1. Sheet: Instruksi
        $instr = $spreadsheet->getActiveSheet();
        $instr->setTitle('Instruksi');
        $this->buildInstructionSheet($instr);

        // 2. Sheet: Tata Cara Pengisian
        $tata = $spreadsheet->createSheet();
        $tata->setTitle('Tata Cara Pengisian');
        $this->buildTataCaraSheet($tata);

        // 3. Sheet: Pengisian Data Pembelian
        $dataSheet = $spreadsheet->createSheet();
        $dataSheet->setTitle(self::DATA_SHEET_NAME);
        $this->buildDataSheet($dataSheet);

        // 4. Sheet: Master Pemasok
        $pemasokSheet = $spreadsheet->createSheet();
        $pemasokSheet->setTitle('Master Pemasok');
        $this->buildMasterPemasokSheet($pemasokSheet);

        // 5. Sheet: Master Lokasi
        $lokasiSheet = $spreadsheet->createSheet();
        $lokasiSheet->setTitle('Master Lokasi');
        $this->buildMasterLokasiSheet($lokasiSheet);

        // 6. Sheet: Master Produk SKU
        $skuSheet = $spreadsheet->createSheet();
        $skuSheet->setTitle('Master Produk SKU');
        $this->buildMasterSkuSheet($skuSheet);

        // 7. Sheet: Master Pajak
        $taxSheet = $spreadsheet->createSheet();
        $taxSheet->setTitle('Master Pajak');
        $this->buildMasterTaxSheet($taxSheet);

        $spreadsheet->setActiveSheetIndex(2); // Focus on data sheet

        return $spreadsheet;
    }

    public function preview(UploadedFile $file): array
    {
        $rows = $this->readDataRows($file);

        if (empty($rows)) {
            throw new \Exception('Sheet "' . self::DATA_SHEET_NAME . '" kosong atau tidak ditemukan data.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new \Exception('Maksimal ' . self::MAX_ROWS . ' baris data per file. File berisi ' . count($rows) . ' baris.');
        }

        $errors = [];
        $warnings = [];

        // Load master data maps for O(1) in-memory lookup
        $suppliers = Contact::whereIn('type', [Contact::TYPE_SUPPLIER, Contact::TYPE_BOTH])
            ->get(['id', 'name', 'phone', 'email'])
            ->keyBy(fn ($c) => strtolower(trim($c->name)));

        $locations = Location::where('is_warehouse', true)
            ->get(['id', 'location_name', 'location_code'])
            ->keyBy(fn ($l) => strtolower(trim($l->location_name)));

        $taxes = Tax::where('is_active', true)
            ->get(['id', 'name', 'rate'])
            ->keyBy(fn ($t) => strtolower(trim($t->name)));

        // Load all SKU variants referenced in rows
        $skusInFile = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? $row['kode_sku'] ?? ''));
            if ($sku !== '') {
                $skusInFile[] = $sku;
            }
        }
        $variants = ProductVariant::with('product:id,name')
            ->whereIn('sku', array_unique($skusInFile))
            ->get()
            ->keyBy(fn ($v) => strtolower(trim($v->sku)));

        $groups = $this->groupRows($rows);

        $documents = [];
        $payloads = [];
        $seenPoNumbers = [];

        foreach ($groups as $group) {
            $headerRowNo = $group['header_row_no'];
            $groupErrors = [];

            // 1. PO Number
            $rawPoNum = trim((string) ($group['po_number'] ?? ''));
            $isAutoPo = $rawPoNum === '' || strtolower($rawPoNum) === '[auto]';
            $poNumber = $isAutoPo ? null : $rawPoNum;

            if ($poNumber !== null) {
                $poLower = strtolower($poNumber);
                if (isset($seenPoNumbers[$poLower])) {
                    $groupErrors[] = "Baris {$headerRowNo}: No. Pesanan \"{$poNumber}\" duplikat di dalam file.";
                } elseif (PurchaseOrder::where('po_number', $poNumber)->exists()) {
                    $groupErrors[] = "Baris {$headerRowNo}: No. Pesanan \"{$poNumber}\" sudah terdaftar di sistem.";
                }
                $seenPoNumbers[$poLower] = true;
            }

            // 2. Supplier
            $rawSupplier = trim((string) ($group['supplier_name'] ?? ''));
            $contact = $rawSupplier !== '' ? ($suppliers->get(strtolower($rawSupplier))) : null;

            if ($rawSupplier === '') {
                $groupErrors[] = "Baris {$headerRowNo}: Nama Pemasok wajib diisi.";
            } elseif (! $contact) {
                $groupErrors[] = "Baris {$headerRowNo}: Pemasok \"{$rawSupplier}\" tidak ditemukan di sistem. Pastikan nama sesuai Master Pemasok.";
            }

            // 3. Location
            $rawLocation = trim((string) ($group['location_name'] ?? ''));
            $location = $rawLocation !== '' ? ($locations->get(strtolower($rawLocation))) : null;

            if ($rawLocation === '') {
                $groupErrors[] = "Baris {$headerRowNo}: Lokasi gudang wajib diisi.";
            } elseif (! $location) {
                $groupErrors[] = "Baris {$headerRowNo}: Lokasi \"{$rawLocation}\" tidak ditemukan di sistem. Pastikan nama sesuai Master Lokasi.";
            }

            // 4. Order Date
            $rawDate = trim((string) ($group['order_date'] ?? ''));
            $orderDate = $this->parseDate($rawDate);
            if ($rawDate === '') {
                $orderDate = now()->toDateString();
            } elseif (! $orderDate) {
                $groupErrors[] = "Baris {$headerRowNo}: Format tanggal \"{$rawDate}\" tidak valid (Gunakan format YYYY-MM-DD atau DD-MM-YYYY).";
            }

            // 5. Tax Included
            $rawTaxInc = strtolower(trim((string) ($group['is_tax_included'] ?? '')));
            $isTaxIncluded = in_array($rawTaxInc, ['true', '1', 'ya', 'yes'], true);

            // 6. Notes & Ref No
            $notes = trim((string) ($group['notes'] ?? '')) ?: null;
            $refNo = trim((string) ($group['ref_no'] ?? '')) ?: null;

            // 7. Validate Items
            $validItems = [];
            $docSubTotal = 0;
            $docTotalDisc = 0;
            $docTotalTax = 0;

            foreach ($group['items'] as $itemLine) {
                $itemRowNo = $itemLine['row_no'];
                $sku = trim((string) ($itemLine['sku'] ?? ''));

                if ($sku === '') {
                    $groupErrors[] = "Baris {$itemRowNo}: SKU produk wajib diisi.";
                    continue;
                }

                $variant = $variants->get(strtolower($sku));
                if (! $variant) {
                    $groupErrors[] = "Baris {$itemRowNo}: SKU \"{$sku}\" tidak terdaftar di master produk.";
                    continue;
                }

                $rawQty = $itemLine['qty'] ?? null;
                if ($rawQty === null || $rawQty === '' || ! is_numeric($rawQty) || (int) $rawQty < 1) {
                    $groupErrors[] = "Baris {$itemRowNo}: Qty untuk SKU \"{$sku}\" harus berupa angka minimal 1.";
                    continue;
                }
                $qty = (int) $rawQty;

                $rawPrice = $itemLine['price'] ?? null;
                if ($rawPrice === null || $rawPrice === '' || ! is_numeric($rawPrice) || (float) $rawPrice < 0) {
                    $groupErrors[] = "Baris {$itemRowNo}: Harga untuk SKU \"{$sku}\" harus berupa angka positif.";
                    continue;
                }
                $unitPrice = (float) $rawPrice;

                // Discount (amount in rupiah or percentage)
                $rawDisc = $itemLine['disc'] ?? 0;
                $discAmount = is_numeric($rawDisc) && (float) $rawDisc >= 0 ? (float) $rawDisc : 0;
                $lineTotal = $unitPrice * $qty;

                // Calculate discount percentage if discAmount is given as nominal
                $discPercent = $lineTotal > 0 ? round(($discAmount / $lineTotal) * 100, 4) : 0;
                if ($discPercent > 100) {
                    $discPercent = 100;
                    $discAmount = $lineTotal;
                }

                // Tax calculation
                $rawTax = trim((string) ($itemLine['tax'] ?? ''));
                $taxId = null;
                $taxAmount = 0;

                if ($rawTax !== '' && strtolower($rawTax) !== 'no tax' && strtolower($rawTax) !== 'tanpa pajak') {
                    $taxModel = $taxes->get(strtolower($rawTax));
                    if (! $taxModel) {
                        // Attempt fallback lookup matching partial rate or name (e.g. "PPN 11%", "PPN 12%", "PPN")
                        $matched = $taxes->first(fn ($t) => str_contains(strtolower($t->name), strtolower($rawTax)) || str_contains(strtolower($rawTax), strtolower($t->name)));
                        if ($matched) {
                            $taxModel = $matched;
                        }
                    }

                    if ($taxModel) {
                        $taxId = $taxModel->id;
                        $rate = (float) $taxModel->rate;
                        $netLine = max(0, $lineTotal - $discAmount);

                        if ($isTaxIncluded) {
                            $taxAmount = round($netLine - ($netLine / (1 + ($rate / 100))), 2);
                        } else {
                            $taxAmount = round($netLine * ($rate / 100), 2);
                        }
                    } else {
                        $warnings[] = [
                            'row' => $itemRowNo,
                            'field' => 'tax',
                            'warning' => "Pajak \"{$rawTax}\" tidak ditemukan di sistem. Dianggap tanpa pajak (No Tax).",
                        ];
                    }
                }

                $addTax = $isTaxIncluded ? 0 : $taxAmount;
                $lineAmount = round($lineTotal - $discAmount + $addTax, 2);

                $docSubTotal += $lineTotal;
                $docTotalDisc += $discAmount;
                $docTotalTax += $taxAmount;

                $validItems[] = [
                    'row_no'       => $itemRowNo,
                    'item_id'      => $variant->id,
                    'sku'          => $variant->sku,
                    'product_name' => $variant->product?->name ?? '',
                    'qty'          => $qty,
                    'unit_price'   => $unitPrice,
                    'disc'         => $discPercent,
                    'disc_amount'  => $discAmount,
                    'tax_id'       => $taxId,
                    'tax_name'     => $rawTax ?: 'No Tax',
                    'tax_amount'   => $taxAmount,
                    'amount'       => $lineAmount,
                ];
            }

            if (empty($validItems) && empty($groupErrors)) {
                $groupErrors[] = "Baris {$headerRowNo}: Pesanan pembelian tidak memiliki baris item barang.";
            }

            $docTotalAmount = round($docSubTotal - $docTotalDisc + ($isTaxIncluded ? 0 : $docTotalTax), 2);
            $status = empty($groupErrors) ? 'ready' : 'error';

            $documents[] = [
                'po_number'        => $isAutoPo ? '(Otomatis)' : $poNumber,
                'ref_no'           => $refNo,
                'supplier_name'    => $rawSupplier,
                'location_name'    => $rawLocation,
                'order_date'       => $orderDate,
                'is_tax_included'  => $isTaxIncluded,
                'notes'            => $notes,
                'item_count'       => count($group['items']),
                'sub_total'        => $docSubTotal,
                'total_disc'       => $docTotalDisc,
                'total_tax'        => $docTotalTax,
                'total_amount'     => $docTotalAmount,
                'status'           => $status,
                'errors'           => array_values($groupErrors),
                'items'            => $validItems,
            ];

            foreach ($groupErrors as $errMsg) {
                $errors[] = ['row' => $headerRowNo, 'field' => 'purchase_order', 'error' => $errMsg];
            }

            if ($status === 'ready' && $contact && $location) {
                $payloads[] = [
                    'po_number'       => $poNumber,
                    'contact_id'      => $contact->id,
                    'location_id'     => $location->id,
                    'order_date'      => $orderDate,
                    'ref_no'          => $refNo,
                    'is_tax_included' => $isTaxIncluded,
                    'notes'           => $notes,
                    'sub_total'       => $docSubTotal,
                    'total_disc'      => $docTotalDisc,
                    'total_tax'       => $docTotalTax,
                    'total_amount'    => $docTotalAmount,
                    'items'           => array_map(fn ($it) => [
                        'item_id'     => $it['item_id'],
                        'qty'         => $it['qty'],
                        'unit_price'  => $it['unit_price'],
                        'disc'        => $it['disc'],
                        'disc_amount' => $it['disc_amount'],
                        'tax_id'      => $it['tax_id'],
                        'tax_amount'  => $it['tax_amount'],
                        'amount'      => $it['amount'],
                    ], $validItems),
                ];
            }
        }

        $token = 'imp_po_' . Str::uuid()->toString();

        $summary = [
            'total_rows'  => count($rows),
            'total_docs'  => count($documents),
            'valid_docs'  => count($payloads),
            'invalid_docs' => count($documents) - count($payloads),
            'errors'      => count($errors),
            'warnings'    => count($warnings),
        ];

        Cache::put(self::CACHE_PREFIX . $token, [
            'payloads' => $payloads,
            'summary'  => $summary,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'token'     => $token,
            'documents' => $documents,
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
            throw new \Exception('Preview token kadaluarsa atau tidak ditemukan. Silakan upload ulang file Excel.');
        }

        $payloads = $preview['payloads'] ?? [];
        if (empty($payloads)) {
            throw new \Exception('Tidak ada data pesanan pembelian valid untuk di-import.');
        }

        $created = 0;
        $failed = 0;
        $poNumbers = [];
        $errors = [];

        foreach ($payloads as $idx => $payload) {
            $label = $payload['po_number'] ?? '(Otomatis #' . ($idx + 1) . ')';
            try {
                DB::transaction(function () use ($payload, $createdBy, &$poNumbers) {
                    $payload['created_by'] = $createdBy;
                    $po = $this->poService->create($payload);
                    $poNumbers[] = $po->po_number;
                });

                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Pesanan {$label}: " . $e->getMessage();
            }
        }

        $this->forgetPreview($token);

        return [
            'created'    => $created,
            'failed'     => $failed,
            'po_numbers' => $poNumbers,
            'errors'     => $errors,
        ];
    }

    protected function groupRows(array $rows): array
    {
        $groups = [];
        $current = null;

        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 2; // 1-based, skipping header row 1

            $poRaw = trim((string) ($row['no_pesanan'] ?? $row['no._pesanan'] ?? $row['po_number'] ?? ''));
            $supRaw = trim((string) ($row['nama_pemasok'] ?? $row['pemasok'] ?? $row['supplier_name'] ?? ''));
            $locRaw = trim((string) ($row['lokasi'] ?? $row['location_name'] ?? ''));
            $dateRaw = trim((string) ($row['tanggal'] ?? $row['order_date'] ?? ''));

            // A new group starts if PO Number is present OR if Supplier/Location/Date is present
            $isNewHeader = ($poRaw !== '') || ($supRaw !== '' && $locRaw !== '');

            if ($isNewHeader || $current === null) {
                if ($current !== null) {
                    $groups[] = $current;
                }

                $current = [
                    'header_row_no'   => $rowNo,
                    'po_number'       => $poRaw,
                    'ref_no'          => $row['no_ref_pemasok'] ?? $row['no._ref_pemasok'] ?? $row['ref_no'] ?? null,
                    'order_date'      => $dateRaw,
                    'timezone'        => $row['zona_waktu'] ?? 'WIB',
                    'supplier_name'   => $supRaw,
                    'supplier_email'  => $row['email_pemasok'] ?? null,
                    'supplier_phone'  => $row['no_telp._pemasok'] ?? $row['no_telp_pemasok'] ?? null,
                    'is_tax_included' => $row['harga_termasuk_pajak'] ?? false,
                    'location_name'   => $locRaw,
                    'notes'           => $row['keterangan'] ?? $row['notes'] ?? null,
                    'items'           => [],
                ];
            }

            // Add item line
            $current['items'][] = [
                'row_no' => $rowNo,
                'sku'    => $row['sku'] ?? $row['kode_sku'] ?? '',
                'price'  => $row['harga'] ?? $row['unit_price'] ?? 0,
                'qty'    => $row['qty'] ?? $row['kuantitas'] ?? 1,
                'disc'   => $row['nilai_diskon'] ?? $row['diskon'] ?? 0,
                'tax'    => $row['pajak'] ?? 'No Tax',
            ];
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    protected function readDataRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'csv') {
            return $this->readCsvRows($file);
        }

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

        $rawHeader = array_shift($data);
        $header = array_map(function ($h) {
            $cleaned = strtolower(trim((string) $h));
            $cleaned = str_replace([' ', '-', '/'], '_', $cleaned);
            return $cleaned;
        }, $rawHeader);

        $rows = [];
        foreach ($data as $row) {
            $nonEmpty = array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '');
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

    protected function readCsvRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return [];
        }

        $rawHeader = fgetcsv($handle);
        if (! $rawHeader) {
            fclose($handle);
            return [];
        }

        // Remove BOM if present
        if (isset($rawHeader[0])) {
            $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $rawHeader[0]);
        }

        $header = array_map(function ($h) {
            $cleaned = strtolower(trim((string) $h));
            $cleaned = str_replace([' ', '-', '/'], '_', $cleaned);
            return $cleaned;
        }, $rawHeader);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $nonEmpty = array_filter($data, fn ($v) => $v !== null && trim((string) $v) !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            $assoc = [];
            foreach ($header as $i => $col) {
                if ($col === '') {
                    continue;
                }
                $assoc[$col] = $data[$i] ?? null;
            }
            $rows[] = $assoc;
        }

        fclose($handle);
        return $rows;
    }

    protected function parseDate(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $formats = [
            'Y-m-d',
            'd-m-Y',
            'd/m/Y',
            'Y/m/d',
            'Y-m-d H:i:s',
            'd-m-Y H:i:s',
            'd/m/Y H:i:s',
        ];

        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildInstructionSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Tata Cara Penggunaan Form Impor Pesanan Pembelian');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1F4E79');

        $steps = [
            [
                'Langkah Pertama',
                'Bacalah ketentuan-ketentuan pada sheet kedua yaitu "Tata Cara Pengisian". Di dalam sheet tersebut terdapat panduan tentang tata cara mengisi form import pesanan pembelian. Pada ketentuan tersebut terdapat dua warna yaitu Orange (kolom wajib diisi) dan Kuning (kolom opsional yang boleh dikosongkan).',
            ],
            [
                'Langkah Kedua',
                'Isi data transaksi pembelian pada sheet "Pengisian Data Pembelian". Anda dapat menggunakan data referensi pada sheet Master Pemasok, Master Lokasi, Master Produk SKU, dan Master Pajak untuk memastikan data yang Anda masukkan cocok dengan sistem.',
            ],
            [
                'Langkah Ketiga',
                'Satu dokumen pesanan pembelian dapat memiliki banyak item barang. Untuk transaksi dengan banyak item, cukup isi data header (No. Pesanan, Pemasok, Lokasi, Tanggal) pada baris PERTAMA, lalu baris item berikutnya di bawahnya cukup isi SKU, Harga, Qty, Diskon, dan Pajak.',
            ],
            [
                'Langkah Keempat',
                'Setelah file disimpan, unggah file tersebut melalui tombol Import di menu Transaksi Pembelian. Sistem akan menampilkan Pratinjau (Preview) terlebih dahulu agar Anda dapat memeriksa kevalidan data sebelum data benar-benar disimpan ke database.',
            ],
        ];

        $r = 3;
        foreach ($steps as [$title, $desc]) {
            $sheet->setCellValue("A{$r}", $title);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('2F5597');
            $r++;

            $sheet->setCellValue("A{$r}", $desc);
            $sheet->mergeCells("A{$r}:E{$r}");
            $sheet->getStyle("A{$r}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getRowDimension($r)->setRowHeight(45);
            $r += 2;
        }

        $sheet->getColumnDimension('A')->setWidth(110);
    }

    private function buildTataCaraSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Tata Cara Pengisian Form Pesanan Pembelian');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headers = ['Nama Label', 'Definisi dan Penggunaan', 'Nilai Yang Diterima', 'Contoh', 'Diharuskan'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}3", $h);
            $sheet->getStyle("{$col}3")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER);
        }

        $rules = [
            ['No. Pesanan', 'No referensi pembelian', 'Bebas, atau [auto] bila ingin mengambil penomoran otomatis', 'PO-2026-0001', 'Wajib Untuk Header', true],
            ['No. Ref Pemasok', 'No referensi dokumen dari pemasok', 'Bebas', 'INV-SUP-9988', 'Tidak', false],
            ['Tanggal', 'Tanggal pembelian', 'Format YYYY-MM-DD atau DD-MM-YYYY', '2026-08-17', 'Wajib Untuk Header', true],
            ['Zona Waktu', 'Zona waktu pengguna', 'WIB, WITA, WIT', 'WIB', 'Wajib Untuk Header', true],
            ['Nama Pemasok', 'Nama pemasok yang terdaftar', 'Sesuai sheet Master Pemasok', 'PT. Mitra Sentosa', 'Wajib Untuk Header', true],
            ['Email Pemasok', 'Email pemasok', 'Format email valid', 'supplier@email.com', 'Tidak', false],
            ['No Telp. Pemasok', 'Nomor telepon pemasok', 'Angka tanpa spasi/strip', '081234567890', 'Tidak', false],
            ['Harga Termasuk Pajak', 'Apakah harga sudah termasuk pajak', 'TRUE (termasuk) atau FALSE (belum termasuk)', 'FALSE', 'Wajib Untuk Header', true],
            ['Lokasi', 'Lokasi gudang penerima', 'Sesuai sheet Master Lokasi', 'Pusat', 'Wajib Untuk Header', true],
            ['Keterangan', 'Catatan pesanan pembelian', 'Bebas', 'Barang harus dicek sebelum kirim', 'Tidak', false],
            ['SKU', 'Kode SKU varian barang', 'Sesuai sheet Master Produk SKU', 'ULENS-BLACK-IP15', 'Wajib', true],
            ['Harga', 'Harga beli satuan barang', 'Numerik angka (tanpa titik koma mata uang)', '50000', 'Wajib', true],
            ['Qty', 'Jumlah kuantitas barang', 'Numerik angka bilangan bulat minimal 1', '100', 'Wajib', true],
            ['Nilai Diskon', 'Nilai diskon per total barang dalam Rupiah', 'Numerik angka (contoh: 5000 atau 0)', '0', 'Wajib', true],
            ['Pajak', 'Kode / Nama Pajak', 'Sesuai sheet Master Pajak, atau "No Tax"', 'PPN 11%', 'Wajib', true],
        ];

        foreach ($rules as $idx => $rule) {
            $rowNum = $idx + 4;
            foreach (array_slice($rule, 0, 5) as $colIdx => $val) {
                $col = chr(65 + $colIdx);
                $sheet->setCellValue("{$col}{$rowNum}", $val);
            }
            $isReq = $rule[5];
            $color = $isReq ? self::COLOR_REQUIRED : self::COLOR_OPTIONAL;
            $sheet->getStyle("A{$rowNum}:E{$rowNum}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($color);
        }

        foreach (['A' => 24, 'B' => 38, 'C' => 45, 'D' => 22, 'E' => 20] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
    }

    private function buildDataSheet($sheet): void
    {
        $headers = [
            'No. Pesanan', 'No. Ref Pemasok', 'Tanggal', 'Zona Waktu', 'Nama Pemasok',
            'Email Pemasok', 'No Telp. Pemasok', 'Harga Termasuk Pajak', 'Lokasi',
            'Keterangan', 'SKU', 'Harga', 'Qty', 'Nilai Diskon', 'Pajak'
        ];

        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
            $sheet->getStyle("{$col}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER);
            $sheet->getStyle("{$col}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Set text format for phone, SKU, and date columns
        foreach (['A', 'B', 'C', 'G', 'K'] as $col) {
            $sheet->getStyle("{$col}2:{$col}1000")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        // Sample Data: PO 1 with 2 items, PO 2 with 1 item
        $supplierExample = Contact::where('type', Contact::TYPE_SUPPLIER)->value('name') ?? 'Pemasok Contoh';
        $locationExample = Location::where('is_warehouse', true)->value('location_name') ?? 'Pusat';
        $variantsExample = ProductVariant::take(3)->pluck('sku')->toArray();
        $sku1 = $variantsExample[0] ?? 'SKU-CONTOH-01';
        $sku2 = $variantsExample[1] ?? 'SKU-CONTOH-02';
        $sku3 = $variantsExample[2] ?? 'SKU-CONTOH-03';

        $samples = [
            ['[auto]', 'REF-001', Carbon::now()->format('Y-m-d'), 'WIB', $supplierExample, 'supplier@email.com', '081234567890', 'FALSE', $locationExample, 'Pengiriman reguler', $sku1, 50000, 100, 0, 'No Tax'],
            ['', '', '', '', '', '', '', '', '', '', $sku2, 75000, 50, 5000, 'No Tax'],
            ['PO-MANUAL-002', 'REF-002', Carbon::now()->format('Y-m-d'), 'WIB', $supplierExample, 'supplier@email.com', '081234567890', 'FALSE', $locationExample, 'Pesanan mendesak', $sku3, 120000, 20, 0, 'No Tax'],
        ];

        foreach ($samples as $rowIdx => $row) {
            $rowNum = $rowIdx + 2;
            foreach ($row as $colIdx => $val) {
                $col = Coordinate::stringFromColumnIndex($colIdx + 1);
                if (in_array($col, ['A', 'B', 'C', 'G', 'K'], true)) {
                    $sheet->setCellValueExplicit("{$col}{$rowNum}", (string) $val, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue("{$col}{$rowNum}", $val);
                }
            }
        }

        foreach (range(1, count($headers)) as $colIdx) {
            $col = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function buildMasterPemasokSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Master Data Pemasok Terdaftar');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $headers = ['Nama Pemasok', 'No. Telepon', 'Email', 'Kode'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}2", $h);
            $sheet->getStyle("{$col}2")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER);
        }

        $contacts = Contact::whereIn('type', [Contact::TYPE_SUPPLIER, Contact::TYPE_BOTH])
            ->orderBy('name')
            ->get(['name', 'phone', 'email', 'code']);

        $r = 3;
        foreach ($contacts as $c) {
            $sheet->setCellValue("A{$r}", $c->name);
            $sheet->setCellValueExplicit("B{$r}", (string) ($c->phone ?? '-'), DataType::TYPE_STRING);
            $sheet->setCellValue("C{$r}", $c->email ?? '-');
            $sheet->setCellValue("D{$r}", $c->code ?? '-');
            $r++;
        }

        foreach (['A' => 35, 'B' => 20, 'C' => 28, 'D' => 16] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
    }

    private function buildMasterLokasiSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Master Data Lokasi Gudang Terdaftar');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $headers = ['Nama Lokasi / Gudang', 'Kode Lokasi', 'Tipe'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}2", $h);
            $sheet->getStyle("{$col}2")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER);
        }

        $locations = Location::where('is_warehouse', true)
            ->orderBy('location_name')
            ->get(['location_name', 'location_code', 'location_type']);

        $r = 3;
        foreach ($locations as $loc) {
            $sheet->setCellValue("A{$r}", $loc->location_name);
            $sheet->setCellValue("B{$r}", $loc->location_code ?? '-');
            $sheet->setCellValue("C{$r}", $loc->location_type ?? 'Gudang');
            $r++;
        }

        foreach (['A' => 30, 'B' => 20, 'C' => 20] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
    }

    private function buildMasterSkuSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Master Data Produk & SKU Terdaftar');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $headers = ['SKU Varian', 'Nama Produk', 'Barcode', 'Harga Modal/Beli'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}2", $h);
            $sheet->getStyle("{$col}2")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER);
        }

        $variants = ProductVariant::with('product:id,name')
            ->orderBy('sku')
            ->take(3000)
            ->get(['sku', 'product_id', 'barcode', 'buy_price']);

        $r = 3;
        foreach ($variants as $v) {
            $sheet->setCellValueExplicit("A{$r}", $v->sku, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $v->product?->name ?? '-');
            $sheet->setCellValueExplicit("C{$r}", (string) ($v->barcode ?? '-'), DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", (float) ($v->buy_price ?? 0));
            $r++;
        }

        foreach (['A' => 30, 'B' => 45, 'C' => 22, 'D' => 20] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
    }

    private function buildMasterTaxSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Master Data Pajak Terdaftar');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $headers = ['Nama Pajak', 'Tarif (%)', 'Status'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}2", $h);
            $sheet->getStyle("{$col}2")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("{$col}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER);
        }

        $taxes = Tax::where('is_active', true)->orderBy('rate')->get();

        $r = 3;
        $sheet->setCellValue("A{$r}", 'No Tax');
        $sheet->setCellValue("B{$r}", 0);
        $sheet->setCellValue("C{$r}", 'Aktif');
        $r++;

        foreach ($taxes as $t) {
            $sheet->setCellValue("A{$r}", $t->name);
            $sheet->setCellValue("B{$r}", (float) $t->rate);
            $sheet->setCellValue("C{$r}", $t->is_active ? 'Aktif' : 'Non-Aktif');
            $r++;
        }

        foreach (['A' => 25, 'B' => 15, 'C' => 15] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
    }
}
