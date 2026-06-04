<?php

namespace Modules\Product\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Modules\Product\Services\ProductImportService;

class ProductImport implements WithMultipleSheets, SkipsUnknownSheets
{
    protected ProductImportService $service;

    public function __construct(ProductImportService $service)
    {
        $this->service = $service;
    }

    public function sheets(): array
    {
        return [
            'Pengisian Import Produk' => new ProductDataSheetImport($this->service),
            // Fallback for generic name if sheet is renamed
            2 => new ProductDataSheetImport($this->service),
        ];
    }

    public function onUnknownSheet($sheetName)
    {
        // Skip
    }
}
