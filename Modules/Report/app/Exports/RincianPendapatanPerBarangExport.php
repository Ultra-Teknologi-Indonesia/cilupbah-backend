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
 * Rincian Pendapatan per Barang — 1 baris per invoice item (FAKTUR).
 * Nett Sales = (Harga×QTY − Diskon Per Barang) − Potongan Biaya − Biaya Proses.
 * Gross Profit = Nett Sales − HPP. Fee level-order ditampilkan per baris (ikut contoh).
 */
class RincianPendapatanPerBarangExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithColumnFormatting, WithStyles, WithTitle
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
            'Tipe Transaksi', 'Tanggal', 'No Pesanan', 'REF', 'Status', 'Channel',
            'Nama Toko', 'Lokasi', 'Pelanggan', 'Nama Barang', 'SKU', 'QTY', 'Harga',
            'Sub Total', 'Diskon Per Barang', 'Termasuk Pajak', 'Pajak', 'Diskon Lainnya',
            'Biaya Lainnya', 'Potongan Biaya', 'Ongkir', 'Asuransi', 'Tips Shopify',
            'Biaya Proses Pesanan', 'Salesmen', 'HPP', 'Nett Sales', 'Gross Profit',
        ];
    }

    public function map($row): array
    {
        $qty = (float) ($row->qty ?? 0);
        $harga = (float) ($row->unit_price ?? 0);
        $subTotal = (float) ($row->subtotal ?? ($harga * $qty));
        $diskonBarang = (float) ($row->disc_amount ?? 0);
        $potonganBiaya = (float) ($row->potongan_biaya ?? 0);
        $biayaProses = (float) ($row->biaya_proses ?? 0);
        $hpp = (float) ($row->total_cogs ?? 0);

        $nett = ($harga * $qty - $diskonBarang) - $potonganBiaya - $biayaProses;

        return [
            'FAKTUR',
            $row->tgl ? Carbon::parse($row->tgl)->format('Y-m-d H:i:s') : '',
            $row->invoice_no ?? '',
            $row->so_no ?? '',
            $row->ch_status ?? '',
            ChannelLabel::for($row->src),
            $row->shop_label ?? '',
            $row->loc_name ?? '',
            $row->cust ?? '',
            $row->line_desc ?? '',
            $row->line_sku ?? '',
            $qty,
            $harga,
            $subTotal,
            $diskonBarang,
            $row->inc_tax ? 'YA' : 'TIDAK',
            (float) ($row->tax_amount ?? 0),
            (float) ($row->diskon_lain ?? 0),
            (float) ($row->biaya_lain ?? 0),
            $potonganBiaya,
            (float) ($row->ongkir ?? 0),
            (float) ($row->asuransi ?? 0),
            0,
            $biayaProses,
            $row->salesman_name ?? '',
            $hpp,
            $nett,
            $nett - $hpp,
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 14, 'B' => 20, 'C' => 16, 'D' => 26, 'E' => 14, 'F' => 16,
            'G' => 24, 'H' => 16, 'I' => 20, 'J' => 44, 'K' => 24, 'L' => 8,
            'M' => 12, 'N' => 12, 'O' => 16, 'P' => 10, 'Q' => 10, 'R' => 14,
            'S' => 12, 'T' => 14, 'U' => 12, 'V' => 12, 'W' => 12, 'X' => 18,
            'Y' => 16, 'Z' => 12, 'AA' => 14, 'AB' => 14,
        ];
    }

    public function columnFormats(): array
    {
        $money = [];
        foreach (['L', 'M', 'N', 'O', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Z', 'AA', 'AB'] as $col) {
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
