<?php

namespace Modules\Sales\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Sales\Models\SalesOrder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SettlementReportExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly Builder|Collection $orders,
    ) {}

    public function query(): Builder
    {
        if ($this->orders instanceof Builder) {
            return $this->orders;
        }

        return SalesOrder::query()
            ->whereKey($this->orders->pluck('id')->all())
            ->with([
                'feeLines',
                'shop:shop_id,shop_name,channel_id',
                'shop.channel:id,code,name',
            ])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return [
            'No Pesanan',
            'Channel',
            'Nama Toko',
            'Tgl Pesanan',
            'Gross',
            'Biaya Admin',
            'Biaya Layanan',
            'Ongkir',
            'Voucher',
            'Biaya Lain',
            'Net Settlement',
            'Tgl Cair',
            'Status',
        ];
    }

    public function map($order): array
    {
        $voucher = $this->num($order->seller_voucher)
            + $this->num($order->platform_voucher)
            + $this->num($order->payment_voucher);

        $otherFees = $this->num($order->transaction_fee)
            + $this->num($order->affiliate_commission)
            + $this->num($order->order_processing_fee)
            + $this->num($order->other_fee)
            + $this->num($order->total_tax)
            + $this->num($order->insurance_cost);

        return [
            $order->salesorder_no,
            $order->source,
            $order->shop?->shop_name ?? '-',
            optional($order->transaction_date)?->format('Y-m-d') ?? '-',
            $this->num($order->gross_amount),
            $this->num($order->commission_fee),
            $this->num($order->service_fee),
            $this->num($order->seller_shipping_borne),
            $voucher,
            $otherFees,
            $this->num($order->settlement_amount),
            optional($order->settled_at)?->format('Y-m-d') ?? '-',
            $order->is_settled ? 'Sudah Cair' : 'Belum Cair',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function num($value): float
    {
        return $value === null ? 0.0 : (float) $value;
    }
}
