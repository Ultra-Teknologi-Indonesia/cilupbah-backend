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
 * Rincian Pendapatan — 1 baris per invoice (FAKTUR).
 * Nett Sales = Sub Total − Diskon − Diskon Lainnya. Gross Profit = Nett Sales − HPP.
 */
class RincianPendapatanExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithColumnFormatting, WithStyles, WithTitle
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
            'Tanggal', 'Tipe Transaksi', 'REF', 'No Pesanan', 'Status', 'Status MP',
            'Channel', 'Nama Toko', 'Pelanggan', 'Sub Total', 'Diskon', 'Diskon Lainnya',
            'Potongan Biaya', 'Biaya Lainnya', 'Termasuk Pajak', 'Pajak', 'Ongkir', 'Asuransi',
            'Nett Sales', 'HPP', 'Gross Profit', 'Nilai Escrow', 'Tanggal Complete',
        ];
    }

    public function map($row): array
    {
        $subTotal = (float) ($row->sub_total ?? 0);
        $diskon = (float) ($row->diskon ?? 0);
        $diskonLain = (float) ($row->diskon_lain ?? 0);
        $hpp = (float) ($row->hpp ?? 0);
        $nett = $subTotal - $diskon - $diskonLain;

        return [
            $row->tgl ? Carbon::parse($row->tgl)->format('Y-m-d H:i:s') : '',
            'FAKTUR',
            $row->so_no ?? '',
            $row->invoice_no ?? '',
            $row->ch_status ?? '',
            $row->ch_status ? ucfirst(strtolower($row->ch_status)) : '',
            ChannelLabel::for($row->src),
            $row->shop_label ?? '',
            $row->cust ?? '',
            $subTotal,
            $diskon,
            $diskonLain,
            (float) ($row->potongan_biaya ?? 0),
            (float) ($row->biaya_lain ?? 0),
            $row->inc_tax ? 'YA' : 'TIDAK',
            (float) ($row->pajak ?? 0),
            (float) ($row->ongkir ?? 0),
            (float) ($row->asuransi ?? 0),
            $nett,
            $hpp,
            $nett - $hpp,
            (float) ($row->escrow ?? 0),
            $row->tgl_complete ? Carbon::parse($row->tgl_complete)->format('Y-m-d H:i:s') : '',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20, 'B' => 14, 'C' => 26, 'D' => 16, 'E' => 14, 'F' => 14,
            'G' => 16, 'H' => 24, 'I' => 20, 'J' => 14, 'K' => 14, 'L' => 14,
            'M' => 14, 'N' => 14, 'O' => 14, 'P' => 10, 'Q' => 12, 'R' => 12,
            'S' => 14, 'T' => 12, 'U' => 14, 'V' => 14, 'W' => 20,
        ];
    }

    public function columnFormats(): array
    {
        $money = [];
        foreach (['J', 'K', 'L', 'M', 'N', 'P', 'Q', 'R', 'S', 'T', 'U', 'V'] as $col) {
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
