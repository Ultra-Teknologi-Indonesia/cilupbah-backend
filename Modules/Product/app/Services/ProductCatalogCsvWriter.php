<?php

declare(strict_types=1);

namespace Modules\Product\Services;

use Modules\Product\Exports\ProductCatalogCsvExport;
use RuntimeException;

final class ProductCatalogCsvWriter
{
    public function write(ProductCatalogCsvExport $export, string $targetPath): int
    {
        $handle = fopen($targetPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Tidak dapat membuka berkas katalog untuk ditulis.');
        }

        try {
            $this->writeRow($handle, $export->headings());
            $written = 0;

            foreach ($export->query()->cursor() as $row) {
                $this->writeRow($handle, $export->map($row));
                $written++;
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Tidak dapat menyelesaikan penulisan berkas katalog.');
            }

            return $written;
        } finally {
            fclose($handle);
        }
    }

    private function writeRow($handle, array $row): void
    {
        if (fputcsv($handle, $row, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Tidak dapat menulis baris katalog.');
        }
    }
}
