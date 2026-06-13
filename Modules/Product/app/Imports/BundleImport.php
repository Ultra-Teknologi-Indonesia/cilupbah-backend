<?php

namespace Modules\Product\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Modules\Product\Services\ProductImportService;

class BundleImport implements WithMultipleSheets, SkipsUnknownSheets
{
    protected ProductImportService $service;

    public function __construct(ProductImportService $service)
    {
        $this->service = $service;
    }

    public function sheets(): array
    {
        return [
            'Pengisian Data' => new BundleDataSheetImport($this->service),
            2 => new BundleDataSheetImport($this->service), 
        ];
    }

    public function onUnknownSheet($sheetName)
    {

    }
}
