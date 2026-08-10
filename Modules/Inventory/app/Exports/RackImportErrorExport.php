<?php

namespace Modules\Inventory\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\RackImportRow;

class RackImportErrorExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private RackImportBatch $batch) {}

    public function query(): Builder
    {
        return RackImportRow::query()
            ->where('batch_id', $this->batch->id)
            ->whereIn('status', [RackImportBatch::STATUS_ERROR, RackImportBatch::STATUS_MANUAL_MOVE])
            ->orderBy('row_no');
    }

    public function headings(): array
    {
        return ['Baris', 'SKU', 'Lokasi', 'Rak', 'Status', 'Keterangan'];
    }

    public function map($row): array
    {
        return [
            $row->row_no,
            $row->raw_sku,
            $row->raw_location,
            $row->raw_bin,
            $this->label($row->status),
            $row->message,
        ];
    }

    private function label(string $status): string
    {
        return match ($status) {
            RackImportBatch::STATUS_ERROR => 'Gagal',
            RackImportBatch::STATUS_MANUAL_MOVE => 'Perlu Pindah Bin manual',
            default => $status,
        };
    }
}
