<?php

namespace Modules\Product\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Modules\Product\Services\ProductImportService;
use Illuminate\Support\Facades\Log;

class BundleDataSheetImport implements ToCollection, WithHeadingRow
{
    protected ProductImportService $service;

    public function __construct(ProductImportService $service)
    {
        $this->service = $service;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            // Check if essential columns are present
            if (empty($row['item_code']) || empty($row['sku_composition'])) {
                continue;
            }
            
            try {
                $this->service->processBundleRow($row->toArray());
            } catch (\Exception $e) {
                Log::error("Failed to import bundle row: " . json_encode($row->toArray()) . " - " . $e->getMessage());
            }
        }
    }
}
