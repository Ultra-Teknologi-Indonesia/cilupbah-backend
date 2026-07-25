<?php

namespace Modules\Report\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Report\Support\ChannelLabel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesProductExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithColumnFormatting, WithStyles, WithTitle
{
    private const MONEY_FORMAT = '#,##0';

    public function __construct(
        private readonly Builder $query,
    ) {}

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return 'Data1';
    }

    public function headings(): array
    {
        return [
            'SKU',
            'Nama Barang',
            'No Pesanan',
            'Lokasi',
            'Sumber',
            'Tanggal',
            'Pelanggan',
            'No Telp',
            'QTY',
            'amount',
            'Nama Toko',
            'Status',
            'Catatan',
        ];
    }

    public function map($item): array
    {
        return [
            $item->sku,
            $item->description,
            $item->so_no,
            $item->loc_name ?? '',
            ChannelLabel::for($item->so_source),
            $item->so_date ? Carbon::parse($item->so_date)->format('Y-m-d H:i:s') : '',
            $item->so_customer,
            $item->so_phone ?? '',
            (float) ($item->qty_in_base ?? 0),
            (float) ($item->amount ?? 0),
            $item->shop_label ?? '',
            $item->so_status ?? '',
            $item->so_note ?? '',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 24, 'B' => 44, 'C' => 26, 'D' => 16, 'E' => 16,
            'F' => 20, 'G' => 22, 'H' => 16, 'I' => 8, 'J' => 14,
            'K' => 24, 'L' => 14, 'M' => 30,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'I' => self::MONEY_FORMAT,
            'J' => self::MONEY_FORMAT,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
