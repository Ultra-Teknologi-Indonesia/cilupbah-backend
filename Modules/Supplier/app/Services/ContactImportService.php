<?php

namespace Modules\Supplier\Services;

use Illuminate\Http\UploadedFile;
use Modules\Supplier\Models\Contact;
use Modules\Supplier\Models\ContactCategory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Illuminate\Support\Str;

class ContactImportService
{
    private const COLUMNS = [
        'A' => 'Nama',
        'B' => 'Tipe',
        'C' => 'PKP/Non PKP',
        'D' => 'NPWP',
        'E' => 'NIK',
        'F' => 'Kategori',
        'G' => 'Termin',
        'H' => 'No. Telepon',
        'I' => 'Email',
        'J' => 'Detail Alamat',
        'K' => 'Provinsi',
        'L' => 'Kota',
        'M' => 'Kecamatan',
        'N' => 'Kelurahan',
    ];

    private const TYPE_MAP = [
        'pemasok'               => Contact::TYPE_SUPPLIER, // Pemasok (sendiri)
        'pemasok dan pelanggan' => Contact::TYPE_BOTH,     // Pemasok + Pelanggan (gabung)
        'pemasok & pelanggan'   => Contact::TYPE_BOTH,
        'pemasok + pelanggan'   => Contact::TYPE_BOTH,
    ];

    private const TAX_MAP = [
        'pkp'     => 'PKP',
        'non pkp' => 'NON_PKP',
    ];

    // Warna penanda di sheet Panduan (dirujuk oleh sheet Tata Cara Penggunaan)
    private const COLOR_REQUIRED = 'F4B183'; // oranye  = wajib diisi
    private const COLOR_OPTIONAL = 'FFE699'; // kuning  = boleh dikosongkan

    public function generateTemplate(): string
    {
        $spreadsheet = new Spreadsheet();

        $intro = $spreadsheet->getActiveSheet();
        $intro->setTitle('Tata Cara Penggunaan');
        $this->buildIntroSheet($intro);

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Panduan');
        $this->buildGuideSheet($guide);

        $data = $spreadsheet->createSheet();
        $data->setTitle('Data Import');
        $this->buildDataSheet($data);

        // Buka di sheet instruksi dulu agar dibaca lebih dulu
        $spreadsheet->setActiveSheetIndex(0);

        $path = storage_path('app/temp/template-import-kontak.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    public function validateFile(UploadedFile $file): array
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getPathname());

        $sheet = null;
        foreach ($spreadsheet->getSheetNames() as $name) {
            if (str_contains(strtolower($name), 'data') || str_contains(strtolower($name), 'pengisian')) {
                $sheet = $spreadsheet->getSheetByName($name);
                break;
            }
        }
        $sheet = $sheet ?? $spreadsheet->getSheet($spreadsheet->getSheetCount() - 1);

        $categories = ContactCategory::all()->keyBy(fn ($c) => strtolower(trim($c->name)));
        $existingNames = Contact::pluck('name')->map(fn ($n) => strtolower(trim($n)))->toArray();

        $valid = [];
        $invalid = [];
        $seenNames = [];
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $raw = [];
            foreach (self::COLUMNS as $col => $label) {
                $value = $sheet->getCell($col . $row)->getValue();
                $raw[$label] = is_float($value)
                    ? trim(sprintf('%.0f', $value))
                    : trim((string) ($value ?? ''));
            }

            if (empty($raw['Nama']) && empty($raw['Tipe'])) {
                continue;
            }

            $errors = [];
            $errorFields = [];
            $mapped = [];

            // Catat pesan error sekaligus kolom mana yang bermasalah,
            // agar FE bisa menyorot value yang tidak valid.
            $addError = function ($fields, string $message) use (&$errors, &$errorFields) {
                $errors[] = $message;
                foreach ((array) $fields as $field) {
                    $errorFields[$field] = true;
                }
            };

            if (empty($raw['Nama'])) {
                $addError('Nama', 'Nama wajib diisi');
            } else {
                $mapped['name'] = $raw['Nama'];
                $lowerName = strtolower(trim($raw['Nama']));
                if (in_array($lowerName, $existingNames)) {
                    $addError('Nama', 'Nama sudah terdaftar di sistem');
                }
                if (in_array($lowerName, $seenNames)) {
                    $addError('Nama', 'Nama duplikat dalam file');
                }
                $seenNames[] = $lowerName;
            }

            // Buang keterangan dalam kurung mis. "Pemasok (Sendiri)" -> "pemasok"
            $typeLower = strtolower(trim(preg_replace('/\(.*?\)/', '', $raw['Tipe'])));
            $typeLower = trim(preg_replace('/\s+/', ' ', $typeLower));
            if (empty($raw['Tipe'])) {
                $addError('Tipe', 'Tipe wajib diisi');
            } elseif (! isset(self::TYPE_MAP[$typeLower])) {
                $addError('Tipe', 'Tipe tidak valid (pilihan: Pemasok, Pemasok dan Pelanggan)');
            } else {
                $mapped['type'] = self::TYPE_MAP[$typeLower];
            }

            $taxLower = strtolower(trim($raw['PKP/Non PKP']));
            if (empty($raw['PKP/Non PKP'])) {
                $addError('PKP/Non PKP', 'PKP/Non PKP wajib diisi');
            } elseif (! isset(self::TAX_MAP[$taxLower])) {
                $addError('PKP/Non PKP', 'PKP/Non PKP tidak valid (pilihan: PKP, Non PKP)');
            } else {
                $mapped['tax_type'] = self::TAX_MAP[$taxLower];
            }

            if (($mapped['tax_type'] ?? '') === 'PKP') {
                if (empty($raw['NPWP']) && empty($raw['NIK'])) {
                    $addError(['NPWP', 'NIK'], 'NPWP atau NIK wajib diisi jika PKP');
                }
            }
            if (! empty($raw['NPWP'])) {
                $npwp = preg_replace('/[^0-9]/', '', $raw['NPWP']);
                if (strlen($npwp) < 15) {
                    $addError('NPWP', 'NPWP harus 15 digit');
                }
                $mapped['tax_id'] = $npwp;
            }
            if (! empty($raw['NIK'])) {
                $mapped['nik'] = $raw['NIK'];
            }

            $catLower = strtolower(trim($raw['Kategori']));
            if (empty($raw['Kategori'])) {
                $addError('Kategori', 'Kategori wajib diisi');
            } elseif (! $categories->has($catLower)) {
                $addError('Kategori', "Kategori \"{$raw['Kategori']}\" tidak ditemukan di sistem");
            } else {
                $mapped['category_id'] = $categories->get($catLower)->id;
            }

            if (! empty($raw['Termin'])) {
                if (! is_numeric($raw['Termin'])) {
                    $addError('Termin', 'Termin harus berupa angka');
                } else {
                    $mapped['payment_term'] = (int) $raw['Termin'];
                }
            }

            if (empty($raw['No. Telepon']) && empty($raw['Email'])) {
                $addError(['No. Telepon', 'Email'], 'No. Telepon atau Email wajib diisi (minimal salah satu)');
            }
            if (! empty($raw['No. Telepon'])) {
                $mapped['phone'] = $raw['No. Telepon'];
            }
            if (! empty($raw['Email'])) {
                if (! filter_var($raw['Email'], FILTER_VALIDATE_EMAIL)) {
                    $addError('Email', 'Format email tidak valid');
                }
                $mapped['email'] = $raw['Email'];
            }

            if (empty($raw['Detail Alamat'])) {
                $addError('Detail Alamat', 'Detail Alamat wajib diisi');
            } else {
                $addressParts = [$raw['Detail Alamat']];
                if (! empty($raw['Kelurahan'])) {
                    $addressParts[] = 'Kel. ' . $raw['Kelurahan'];
                }
                if (! empty($raw['Kecamatan'])) {
                    $addressParts[] = 'Kec. ' . $raw['Kecamatan'];
                }
                $mapped['address'] = implode(', ', $addressParts);
            }

            if (! empty($raw['Provinsi'])) {
                $mapped['province'] = $raw['Provinsi'];
            }
            if (! empty($raw['Kota'])) {
                $mapped['city'] = $raw['Kota'];
            }

            $entry = [
                'row'  => $row,
                'raw'  => $raw,
            ];

            if (empty($errors)) {
                $entry['mapped'] = $mapped;
                $valid[] = $entry;
            } else {
                $entry['errors'] = $errors;
                $entry['error_fields'] = array_keys($errorFields);
                $invalid[] = $entry;
            }
        }

        return [
            'valid'         => $valid,
            'invalid'       => $invalid,
            'total'         => count($valid) + count($invalid),
            'valid_count'   => count($valid),
            'invalid_count' => count($invalid),
        ];
    }

    public function saveRows(array $rows): int
    {
        $created = 0;

        foreach ($rows as $row) {
            $data = $row['mapped'] ?? $row;

            $prefix = match ($data['type'] ?? Contact::TYPE_CUSTOMER) {
                Contact::TYPE_SUPPLIER => 'SUP',
                Contact::TYPE_BOTH     => 'CS',
                default                => 'CUST',
            };
            $data['code'] = $prefix . '-' . Str::upper(Str::random(6));
            $data['status'] = Contact::STATUS_ACTIVE;

            Contact::create($data);
            $created++;
        }

        return $created;
    }

    private function buildIntroSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Tata Cara Penggunaan Form Import Pelanggan');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $steps = [
            [
                'Langkah Pertama',
                'Buka sheet "Panduan". Di sana terdapat ketentuan cara mengisi setiap kolom. ' .
                'Terdapat dua warna: kotak berwarna ORANYE adalah kolom yang WAJIB diisi, ' .
                'sedangkan kotak berwarna KUNING adalah kolom yang BOLEH dikosongkan.',
            ],
            [
                'Langkah Kedua',
                'Isi data pada sheet "Data Import" mengikuti ketentuan di sheet Panduan. ' .
                'Jika ada pengisian yang tidak sesuai, saat diunggah sistem akan menandai baris tersebut ' .
                'sebagai "Tidak Valid" beserta alasannya. Perbaiki kembali di Excel lalu unggah ulang.',
            ],
            [
                'Langkah Ketiga',
                'Setelah data terisi dengan benar, simpan file dalam format Excel (.xlsx). ' .
                'Pastikan seluruh data yang akan diimport berada pada sheet "Data Import".',
            ],
            [
                'Langkah Keempat',
                'Buka menu Import pada aplikasi, klik area "Pilih file yang akan di import", ' .
                'lalu pilih file .xlsx yang sudah Anda simpan dan klik tombol "Import". ' .
                'Tinjau hasil pada tab Valid / Tidak Valid, kemudian klik "Simpan Data Valid" untuk menyimpan data.',
            ],
        ];

        $r = 3;
        foreach ($steps as [$title, $body]) {
            $sheet->setCellValue('A' . $r, $title);
            $sheet->getStyle('A' . $r)->getFont()->setBold(true)->setSize(12)
                ->getColor()->setRGB('4472C4');
            $r++;

            $sheet->setCellValue('A' . $r, $body);
            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->getStyle('A' . $r)->getAlignment()
                ->setWrapText(true)
                ->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getRowDimension($r)->setRowHeight(60);
            $r += 2;
        }

        // Legenda warna agar deskripsi pada langkah pertama jelas
        $sheet->setCellValue('A' . $r, 'Keterangan Warna');
        $sheet->getStyle('A' . $r)->getFont()->setBold(true);
        $r++;
        $sheet->setCellValue('B' . $r, 'Wajib diisi');
        $sheet->getStyle('A' . $r)->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::COLOR_REQUIRED);
        $r++;
        $sheet->setCellValue('B' . $r, 'Boleh dikosongkan');
        $sheet->getStyle('A' . $r)->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::COLOR_OPTIONAL);

        foreach (['A' => 22, 'B' => 40, 'C' => 22, 'D' => 22] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    private function buildGuideSheet($sheet): void
    {
        $sheet->setCellValue('A1', 'Panduan Pengisian Import Kontak');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Kolom', 'Nilai Yang Diterima', 'Contoh', 'Wajib'];
        foreach (['A', 'B', 'C', 'D'] as $i => $col) {
            $sheet->setCellValue($col . '3', $headers[$i]);
            $sheet->getStyle($col . '3')->getFont()->setBold(true);
            $sheet->getStyle($col . '3')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('4472C4');
            $sheet->getStyle($col . '3')->getFont()->getColor()->setRGB('FFFFFF');
        }

        // Daftar kategori diambil dinamis dari sistem agar panduan selalu
        // menampilkan value kategori yang benar-benar diterima.
        $categoryNames = ContactCategory::orderBy('name')->pluck('name')->all();
        $categoryAccepted = empty($categoryNames)
            ? 'Harus terdaftar di sistem'
            : implode(' / ', $categoryNames);
        $categoryExample = $categoryNames[0] ?? 'PLG-Umum';

        $guide = [
            ['Nama', 'Teks bebas', 'PT. Anugrah Niagatama', 'Ya'],
            ['Tipe', 'Pemasok (sendiri) / Pemasok + Pelanggan (gabung)', 'Pemasok', 'Ya'],
            ['PKP/Non PKP', 'PKP / Non PKP', 'Non PKP', 'Ya'],
            ['NPWP', '15 digit angka', '022095350628000', 'Ya jika PKP'],
            ['NIK', '16 digit angka', '3174012345670001', 'Tidak'],
            ['Kategori', $categoryAccepted, $categoryExample, 'Ya'],
            ['Termin', 'Angka (hari)', '30', 'Tidak'],
            ['No. Telepon', 'Format telepon', '+628128194725', 'Salah satu wajib'],
            ['Email', 'Format email', 'john@gmail.com', 'Salah satu wajib'],
            ['Detail Alamat', 'Teks bebas', 'Jl. Mangga Dua No 5', 'Ya'],
            ['Provinsi', 'Teks bebas', 'DKI Jakarta', 'Tidak'],
            ['Kota', 'Teks bebas', 'Jakarta Pusat', 'Tidak'],
            ['Kecamatan', 'Teks bebas', 'Sawah Besar', 'Tidak'],
            ['Kelurahan', 'Teks bebas', 'Mangga Dua Selatan', 'Tidak'],
        ];

        foreach ($guide as $i => $row) {
            $r = $i + 4;
            foreach (['A', 'B', 'C', 'D'] as $j => $col) {
                // Kolom "Contoh" (C) ditulis sebagai teks eksplisit agar angka panjang
                // seperti NIK/NPWP/No. Telepon tidak berubah jadi notasi ilmiah (3,17401E+15)
                if ($col === 'C') {
                    $sheet->getStyle($col . $r)->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_TEXT);
                    $sheet->setCellValueExplicit($col . $r, $row[$j], DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($col . $r, $row[$j]);
                }
            }
            $isRequired = ($row[3] === 'Ya' || str_starts_with($row[3], 'Ya ') || $row[3] === 'Salah satu wajib');
            $color = $isRequired ? self::COLOR_REQUIRED : self::COLOR_OPTIONAL;
            $sheet->getStyle("A{$r}:D{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($color);
        }

        foreach (['A' => 20, 'B' => 45, 'C' => 25, 'D' => 18] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    private function buildDataSheet($sheet): void
    {
        foreach (self::COLUMNS as $col => $label) {
            $sheet->setCellValue($col . '1', $label);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getStyle($col . '1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('4472C4');
            $sheet->getStyle($col . '1')->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($col . '1')->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Kolom yang berisi angka panjang harus dipaksa Text agar tidak berubah
        // menjadi notasi ilmiah (mis. NIK 3,17401E+15, No. Telepon 6,28128E+11)
        // saat user mengisi/menyimpan template di Excel.
        $textColumns = ['D' => 'NPWP', 'E' => 'NIK', 'H' => 'No. Telepon'];
        foreach (array_keys($textColumns) as $col) {
            $sheet->getStyle($col . '1:' . $col . '1000')->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $categoryExample = ContactCategory::orderBy('name')->value('name') ?? 'PLG-Umum';
        $example = [
            'PT. Contoh Sejahtera', 'Pemasok', 'Non PKP', '', '',
            $categoryExample, '30', '+628123456789', 'contoh@email.com',
            'Jl. Contoh No. 1', 'DKI Jakarta', 'Jakarta Pusat',
            'Gambir', 'Cideng',
        ];
        foreach (array_values(array_keys(self::COLUMNS)) as $i => $col) {
            $value = $example[$i] ?? '';
            if (isset($textColumns[$col])) {
                $sheet->setCellValueExplicit($col . '2', $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($col . '2', $value);
            }
        }
        $sheet->getStyle('A2:N2')->getFont()->getColor()->setRGB('808080');

        foreach (self::COLUMNS as $col => $label) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
