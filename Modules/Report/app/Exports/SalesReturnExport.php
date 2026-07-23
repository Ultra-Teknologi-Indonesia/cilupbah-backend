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

/**
 * Laporan Retur Penjualan — 1 baris per baris item retur.
 * Harga/diskon diambil dari baris penjualan asli; sub total dihitung dari qty retur.
 */
class SalesReturnExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithColumnFormatting, WithStyles, WithTitle
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
            'tanggal_retur',
            'Lokasi',
            'return_no',
            'invoice_no',
            'salesorder_no',
            'Channel',
            'Nama Toko',
            'Pelanggan',
            'No Resi',
            'No Putaway',
            'Catatan',
            'SKU',
            'Nama Barang',
            'QTY',
            'Harga',
            'Diskon Per Barang',
            'Diskon Lainnya',
            'Sub Total',
            'Sub Total Setelah Diskon',
            'Grand Total',
        ];
    }

    public function map($row): array
    {
        $qty = (float) ($row->qty ?? 0);
        $harga = (float) ($row->line_price ?? 0);
        $disc = (float) ($row->line_disc ?? 0);
        $discLain = 0.0;

        $subTotal = $harga * $qty;
        $subTotalAfter = ($harga - $disc) * $qty - $discLain;

        return [
            $row->return_date ? Carbon::parse($row->return_date)->format('Y-m-d H:i:s') : '',
            $row->loc_name ?? '',
            $row->return_no,
            $row->invoice_no ?? '',
            $row->so_no ?? '',
            ChannelLabel::for($row->ret_source),
            $row->shop_label ?? '',
            $row->ret_customer ?? '',
            $row->ret_resi ?? '',
            $row->putaway_no ?? '',
            $row->ret_notes ?? '',
            $row->line_sku ?? '',
            $row->line_desc ?? '',
            $qty,
            $harga,
            $disc,
            $discLain,
            $subTotal,
            $subTotalAfter,
            $subTotalAfter,
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20, 'B' => 16, 'C' => 16, 'D' => 16, 'E' => 26,
            'F' => 16, 'G' => 24, 'H' => 20, 'I' => 18, 'J' => 16,
            'K' => 24, 'L' => 24, 'M' => 44, 'N' => 8, 'O' => 12,
            'P' => 16, 'Q' => 14, 'R' => 12, 'S' => 20, 'T' => 14,
        ];
    }

    public function columnFormats(): array
    {
        $money = [];
        foreach (['N', 'O', 'P', 'Q', 'R', 'S', 'T'] as $col) {
            $money[$col] = self::MONEY_FORMAT;
        }

        return $money;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
