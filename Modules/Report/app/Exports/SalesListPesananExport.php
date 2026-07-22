<?php

namespace Modules\Report\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Daftar Penjualan — Referensi Transaksi "Pesanan" (1 baris per order).
 * Flat export, chunked lewat FromQuery agar aman untuk puluhan ribu baris.
 */
class SalesListPesananExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithColumnFormatting, WithStyles, WithTitle
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
            'Tanggal',
            'No Pesanan',
            'REF',
            'No Invoice',
            'Channel',
            'Nama Toko',
            'Lokasi',
            'Pelanggan',
            'No Telp',
            'Kurir',
            'Status',
            'Diskon',
            'Diskon Lainnya',
            'Potongan Biaya',
            'Biaya Lainnya',
            'Pajak',
            'Ongkir',
            'Asuransi',
            'Tip Shopify',
            'Biaya Proses Pesanan',
            'Total',
            'Grand Total',
        ];
    }

    public function map($order): array
    {
        return [
            $order->transaction_date?->format('Y-m-d H:i:s'),
            $order->salesorder_no,
            $order->channel_order_no,
            $order->invoice_no ?? '',
            $this->channelLabel($order->source),
            $order->shop_label ?? '',
            $order->loc_name ?? '',
            $order->customer_name,
            $order->shipping_phone ?? '',
            $order->courier_name ?: ($order->shipping_provider ?? ''),
            $order->channel_status ?: ($order->status ?? ''),
            (float) ($order->total_disc ?? 0),
            (float) ($order->other_discount ?? 0),
            (float) ($order->transaction_fee ?? 0),   // "Potongan Biaya" — verifikasi mapping fee di staging
            (float) ($order->service_fee ?? 0),        // "Biaya Lainnya" — verifikasi mapping fee di staging
            (float) ($order->total_tax ?? 0),
            (float) ($order->shipping_cost ?? 0),
            (float) ($order->insurance_cost ?? 0),
            null,                                       // Tip Shopify — khusus Shopify, selalu kosong
            (float) ($order->order_processing_fee ?? 0),
            (float) ($order->sub_total ?? 0),
            (float) ($order->grand_total ?? 0),
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20, 'B' => 26, 'C' => 22, 'D' => 16, 'E' => 16,
            'F' => 24, 'G' => 16, 'H' => 22, 'I' => 16, 'J' => 26,
            'K' => 14, 'L' => 12, 'M' => 14, 'N' => 14, 'O' => 14,
            'P' => 10, 'Q' => 12, 'R' => 12, 'S' => 12, 'T' => 18,
            'U' => 14, 'V' => 14,
        ];
    }

    public function columnFormats(): array
    {
        $money = [];
        foreach (['L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V'] as $col) {
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

    private function channelLabel(?string $source): string
    {
        return match ($source) {
            'shopee'      => 'SHOPEE',
            'tiktok'      => 'Shop | Tokopedia',
            'lazada'      => 'LAZADA',
            'tokopedia'   => 'Tokopedia',
            'woocommerce' => 'WooCommerce',
            'blibli'      => 'Blibli',
            'manual'      => 'Manual',
            'pos'         => 'POS',
            default       => $source ? ucfirst($source) : '',
        };
    }
}
