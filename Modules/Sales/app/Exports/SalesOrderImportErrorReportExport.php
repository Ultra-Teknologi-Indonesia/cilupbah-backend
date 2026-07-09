<?php

namespace Modules\Sales\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Sales\Models\SalesOrderImportBatch;
use Modules\Sales\Models\SalesOrderImportError;

class SalesOrderImportErrorReportExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private SalesOrderImportBatch $batch) {}

    public function query(): Builder
    {
        return SalesOrderImportError::query()
            ->where('import_batch_id', $this->batch->id)
            ->orderBy('row_number');
    }

    public function headings(): array
    {
        return ['Baris', 'Kolom', 'Pesan Error'];
    }

    public function map($row): array
    {
        return [
            $row->row_number,
            $row->attribute,
            $row->message,
        ];
    }
}
