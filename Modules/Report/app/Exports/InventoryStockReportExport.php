<?php

declare(strict_types=1);

namespace Modules\Report\Exports;

use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Report\Services\InventoryStockReportService;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class InventoryStockReportExport implements FromQuery, WithColumnWidths, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private readonly InventoryStockReportService $service,
        private readonly array $filters,
    ) {}

    public function query(): Builder
    {
        return $this->filters['report_type'] === 'by_rack'
            ? $this->service->rackQuery($this->filters)
            : $this->service->query($this->filters);
    }

    public function title(): string
    {
        return $this->filters['report_type'] === 'by_rack' ? 'Persediaan Per Rak' : 'Persediaan Barang';
    }

    public function headings(): array
    {
        if ($this->filters['report_type'] === 'by_rack') {
            return ['SKU', 'Nama Barang', 'Variasi', 'Lokasi', 'Kode Lantai', 'Kode Baris', 'Kode Kolom', 'No Rak', 'Qty On Hand', 'Qty Aktual Sistem'];
        }

        return ['Nama', 'SKU', 'Variasi', 'Status Produk', 'Lokasi', 'Berat (Gram)', 'Harga Jual', 'QTY', 'Dipesan', 'Tersedia', 'Reserved', 'HPP', 'Nilai Persediaan', 'Is Bundle'];
    }

    public function map($row): array
    {
        if ($this->filters['report_type'] === 'by_rack') {
            return [
                $row->sku,
                $row->product_name,
                $row->variant_name,
                $row->location_name,
                $row->floor_code ?? '-',
                $row->row_code ?? '-',
                $row->column_code ?? '-',
                $row->bin_final_code ?? 'Tidak ada rak',
                (int) $row->qty_on_hand,
                (int) $row->qty_actual,
            ];
        }

        return [
            $row->product_name,
            $row->sku,
            $row->variant_name,
            $row->product_status === 'archived' ? 'Archive' : 'Not Archive',
            $row->location_name,
            (float) $row->weight,
            (float) $row->sell_price,
            (int) $row->qty,
            (int) $row->ordered,
            (int) $row->available,
            (int) $row->reserved,
            (float) $row->buy_price,
            (float) $row->inventory_value,
            (bool) $row->is_bundle ? 'Ya' : 'Tidak',
        ];
    }

    public function columnWidths(): array
    {
        if ($this->filters['report_type'] === 'by_rack') {
            return ['A' => 22, 'B' => 38, 'C' => 28, 'D' => 20, 'E' => 14, 'F' => 14, 'G' => 14, 'H' => 22, 'I' => 14, 'J' => 18];
        }

        return ['A' => 38, 'B' => 24, 'C' => 28, 'D' => 16, 'E' => 20, 'F' => 14, 'G' => 15, 'H' => 12, 'I' => 12, 'J' => 12, 'K' => 12, 'L' => 14, 'M' => 18, 'N' => 12];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:'.($this->filters['report_type'] === 'by_rack' ? 'J1' : 'N1'))->getFont()->setBold(true);

        if ($this->filters['report_type'] === 'by_rack') {
            $sheet->getStyle('I:J')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
        } else {
            $sheet->getStyle('F:F')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->getStyle('G:G')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->getStyle('H:K')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
            $sheet->getStyle('L:M')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        }

        return [1 => ['font' => ['bold' => true]]];
    }
}
