<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RackAllocationExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly ?string $locationId = null,
        private readonly ?string $search = null,
    ) {}

    public function collection(): Collection
    {
        $query = DB::table('inventories')
            ->join('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->join('locations', 'locations.id', '=', 'inventories.location_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventories.item_id')
            ->where('location_bins.is_inbound', false)
            ->where('location_bins.bin_final_code', '!=', 'DEFAULT')
            ->where('inventories.on_hand', '>', 0)
            ->when($this->locationId, fn ($q) => $q->where('inventories.location_id', $this->locationId))
            ->when($this->search, function ($q) {
                $s = '%' . $this->search . '%';
                $q->where(function ($w) use ($s) {
                    $w->where('product_variants.sku', 'like', $s)
                        ->orWhere('location_bins.bin_final_code', 'like', $s);
                });
            })
            ->orderBy('product_variants.sku')
            ->orderBy('location_bins.bin_final_code')
            ->select([
                'product_variants.sku as item_code',
                'locations.location_name as location_name',
                'location_bins.bin_final_code as bin_final_code',
            ]);

        return collect($query->get());
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
