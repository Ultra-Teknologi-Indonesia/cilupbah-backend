<?php

namespace Modules\Inventory\Services\RackImport;

use PhpOffice\PhpSpreadsheet\IOFactory;

class RackImportFileReader
{
    private const SKU_ALIASES = ['kode_produk', 'item_code', 'sku'];
    private const LOCATION_ALIASES = ['location_name', 'lokasi', 'nama_lokasi', 'nama lokasi'];
    private const BIN_ALIASES = ['bin_final_code', 'rak', 'kode_rak', 'kode rak', 'kode_final_rak', 'kode final rak'];

    public function read(string $absolutePath): \Generator
    {
        $ext = mb_strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (in_array($ext, ['csv', 'txt'], true)) {
            yield from $this->readCsv($absolutePath);

            return;
        }

        yield from $this->readXlsx($absolutePath);
    }

    private function readCsv(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        $header = null;
        $rowNo = 1;

        try {
            while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($header === null) {
                    $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($line[0] ?? ''));
                    $header = array_map(fn ($h) => $this->norm($h), $line);
                    continue;
                }

                $rowNo++;
                $clean = $this->cleanRow($header, $line, $rowNo);
                if ($clean !== null) {
                    yield $clean;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function readXlsx(string $path): \Generator
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $data = $sheet->toArray(null, true, true, false);
            if (empty($data)) {
                continue;
            }

            $header = array_map(fn ($h) => $this->norm($h), $data[0]);
            if (count(array_intersect(self::SKU_ALIASES, $header)) === 0) {
                continue; 
            }

            $rowNo = 1;
            foreach (array_slice($data, 1) as $line) {
                $rowNo++;
                $clean = $this->cleanRow($header, $line, $rowNo);
                if ($clean !== null) {
                    yield $clean;
                }
            }

            return; 
        }
    }

    private function cleanRow(array $header, array $line, int $rowNo): ?array
    {
        $assoc = [];
        foreach ($header as $i => $col) {
            if ($col === '') {
                continue;
            }
            $assoc[$col] = $line[$i] ?? null;
        }

        $sku = trim((string) $this->pick($assoc, self::SKU_ALIASES));
        $location = trim((string) $this->pick($assoc, self::LOCATION_ALIASES));
        $bin = trim((string) $this->pick($assoc, self::BIN_ALIASES));

        if ($sku === '' && $location === '' && $bin === '') {
            return null;
        }

        return ['row_no' => $rowNo, 'sku' => $sku, 'location' => $location, 'bin' => $bin];
    }

    private function pick(array $assoc, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $assoc) && $assoc[$alias] !== null && $assoc[$alias] !== '') {
                return $assoc[$alias];
            }
        }

        return null;
    }

    private function norm(mixed $h): string
    {
        return mb_strtolower(trim((string) $h));
    }
}
