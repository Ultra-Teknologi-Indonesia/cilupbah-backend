<?php

namespace Modules\Sales\Exports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReturnChannelOnlineSheet implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly Collection $rows,
        private readonly string $sheetTitle,
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Lokasi Gudang',
            'No. Resi',
            'No. Pesanan (Internal)',
            'No. Pesanan (Channel)',
            'Tanggal Penerimaan',
            'SKU',
            'Nama Barang',
            'QTY',
            'Sumber (BIL/SR)',
            'Dibuat Oleh',
            'Diproses Oleh',
        ];
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('4472C4');

        $sheet->getStyle('A1:K1')->getFont()->getColor()->setRGB('FFFFFF');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
