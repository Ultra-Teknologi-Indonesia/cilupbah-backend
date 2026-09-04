<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Product\Support\TechnicalSku;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RackAllocationExport implements FromQuery, WithChunkReading, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly ?string $locationId = null,
        private readonly ?string $search = null,
    ) {}

    public function query()
    {
        $query = TechnicalSku::exclude(DB::table('sku_rack_assignments')
            ->join('location_bins', 'location_bins.id', '=', 'sku_rack_assignments.bin_id')
            ->join('locations', 'locations.id', '=', 'sku_rack_assignments.location_id')
            ->join('product_variants', 'product_variants.id', '=', 'sku_rack_assignments.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->when($this->locationId, fn ($q) => $q->where('sku_rack_assignments.location_id', $this->locationId))
            ->when($this->search, function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($w) use ($s) {
                    $w->where('product_variants.sku', 'ilike', $s)
                        ->orWhere('location_bins.bin_final_code', 'ilike', $s);
                });
            })
            ->orderBy('product_variants.sku')
            ->orderBy('location_bins.bin_final_code')
            ->select([
                'product_variants.sku as item_code',
                'locations.location_name as location_name',
                'location_bins.bin_final_code as bin_final_code',
            ]), 'product_variants.sku');

        return $query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['SKU', 'Lokasi', 'Rak'];
    }

    public function map($row): array
    {
        return [
            $row->item_code,
            $row->location_name,
            $row->bin_final_code,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
