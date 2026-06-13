<?php

namespace Modules\Product\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Modules\Product\Services\ProductImportService;
use Illuminate\Support\Facades\Log;

class ProductDataSheetImport implements ToCollection, WithHeadingRow
{
    protected ProductImportService $service;

    public function __construct(ProductImportService $service)
    {
        $this->service = $service;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            if (empty($row['item_group_name']) || empty($row['item_code'])) {
                continue;
            }

            try {
                $this->service->processSingleProductRow($row->toArray());
            } catch (\Exception $e) {
                Log::error("Failed to import product row: " . json_encode($row->toArray()) . " - " . $e->getMessage());
            }
        }
    }
}
