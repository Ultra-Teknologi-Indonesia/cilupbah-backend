<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Product\Support\TechnicalSku;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RackAllocationExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly ?string $locationId = null,
        private readonly ?string $search = null,
    ) {}

    public function query()
    {

        $assignments = DB::table('sku_rack_assignments')
            ->join('location_bins', 'location_bins.id', '=', 'sku_rack_assignments.bin_id')
            ->join('locations', 'locations.id', '=', 'sku_rack_assignments.location_id')
            ->join('product_variants', 'product_variants.id', '=', 'sku_rack_assignments.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->when($this->locationId, fn ($q) => $q->where('sku_rack_assignments.location_id', $this->locationId))
            ->select([
                'product_variants.sku as item_code',
                'locations.location_name as location_name',
                'location_bins.bin_final_code as bin_final_code',
            ]);

        $stock = DB::table('inventories')
            ->join('location_bins', function ($join): void {
                $join->on('location_bins.id', '=', 'inventories.bin_id')
                    ->on('location_bins.location_id', '=', 'inventories.location_id');
            })
            ->join('locations', 'locations.id', '=', 'inventories.location_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventories.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('location_bins.is_inbound', false)
            ->where('inventories.on_hand', '<>', 0)
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->when($this->locationId, fn ($q) => $q->where('inventories.location_id', $this->locationId))
            ->select([
                'product_variants.sku as item_code',
                'locations.location_name as location_name',
                'location_bins.bin_final_code as bin_final_code',
            ]);

        $query = DB::query()
            ->fromSub($assignments->unionAll($stock), 'rack_allocations')
            ->select(['item_code', 'location_name', 'bin_final_code'])
            ->distinct()
            ->when($this->search, function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($w) use ($s): void {
                    $w->where('item_code', 'ilike', $s)
                        ->orWhere('bin_final_code', 'ilike', $s);
                });
            });

        return TechnicalSku::exclude($query, 'item_code')
            ->orderBy('item_code')
            ->orderBy('bin_final_code');
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
