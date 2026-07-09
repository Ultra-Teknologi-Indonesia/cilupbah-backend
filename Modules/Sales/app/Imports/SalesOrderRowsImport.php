<?php

namespace Modules\Sales\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Modules\Sales\Services\SalesOrderImportService;

class SalesOrderRowsImport implements ToCollection, WithHeadingRow
{
    private array $result = ['total' => 0, 'success' => 0, 'failed' => 0, 'errors' => []];

    public function __construct(private SalesOrderImportService $service) {}

    public function collection(Collection $rows): void
    {
        $this->result = $this->service->processSheet($rows);
    }

    public function result(): array
    {
        return $this->result;
    }
}
