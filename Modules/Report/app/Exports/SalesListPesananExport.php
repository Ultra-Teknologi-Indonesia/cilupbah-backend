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
            $this->channelLabel($order->source),
            $order->shop_label ?? '',
            $order->loc_name ?? '',
            $order->customer_name,
            $order->shipping_phone ?? '',
            $order->courier_name ?: ($order->shipping_provider ?? ''),
            $order->channel_status ?: ($order->status ?? ''),
            (float) ($order->total_disc ?? 0),         
            (float) ($order->platform_voucher ?? 0),   
            (float) ($order->transaction_fee ?? 0),   
            (float) ($order->service_fee ?? 0),        
            (float) ($order->total_tax ?? 0),
            (float) ($order->shipping_cost ?? 0),
            (float) ($order->insurance_cost ?? 0),
            (float) ($order->order_processing_fee ?? 0),
            (float) ($order->sub_total ?? 0),
            (float) ($order->grand_total ?? 0),
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20, 'B' => 26, 'C' => 22, 'D' => 16, 'E' => 24,
            'F' => 16, 'G' => 22, 'H' => 16, 'I' => 26, 'J' => 14,
            'K' => 12, 'L' => 14, 'M' => 14, 'N' => 14, 'O' => 10,
            'P' => 12, 'Q' => 12, 'R' => 18, 'S' => 14, 'T' => 14,
        ];
    }

    public function columnFormats(): array
    {
        $money = [];
        foreach (['K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T'] as $col) {
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
