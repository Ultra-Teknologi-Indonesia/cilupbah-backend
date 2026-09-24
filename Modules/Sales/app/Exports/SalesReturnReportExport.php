<?php

namespace Modules\Sales\Exports;

use App\Support\BusinessDateRange;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Sales\Models\SalesReturn;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesReturnReportExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly ?string $dateFrom,
        private readonly ?string $dateTo,
        private readonly ?string $locationId,
        private readonly ?string $channelShopId,
        private readonly ?string $status,
        private readonly ?string $source,
        private readonly ?string $reasonCategory = null,
        private readonly ?string $marketplaceDecision = null,
    ) {}

    public function query(): Builder
    {
        [$from, $to] = BusinessDateRange::bounds($this->dateFrom, $this->dateTo);

        $q = SalesReturn::query()
            ->with([
                'order:id,salesorder_no,channel_order_no,customer_name',
                'location:id,location_name',
                'settlement.refunds',
            ]);

        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        if ($to) {
            $q->where('created_at', '<', $to);
        }
        if ($this->locationId) {
            $q->where('location_id', $this->locationId);
        }
        if ($this->channelShopId) {
            $q->where('channel_shop_id', $this->channelShopId);
        }
        if ($this->status) {
            $q->where('status', $this->status);
        }
        if ($this->source) {
            $q->where('source', $this->source);
        }
        if ($this->reasonCategory) {
            $q->where('reason_category', $this->reasonCategory);
        }
        if ($this->marketplaceDecision) {
            $q->where('marketplace_decision', $this->marketplaceDecision);
        }

        return $q->orderByDesc('created_at');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return [
            'No. Retur',
            'No. Order',
            'No. Order Marketplace',
            'Pelanggan',
            'Sumber',
            'Status',
            'Alasan',
            'Kategori Alasan',
            'Keputusan Marketplace',
            'Alasan Channel',
            'Lokasi',
            'Diproses Oleh',
            'Diproses Pada',
            'Dibuat',
            'No. Settlement',
            'Status Settlement',
            'Total Settlement',
            'Total Refund',
            'Jumlah Refund',
            'Metode Refund',
            'Tanggal Refund Terakhir',
            'Nominal Refund (Channel)',
            'Ongkir Asli',
            'Ongkir Retur',
            'Selisih Ongkir',
            'Resi Retur',
            'Kurir Retur',
            'Tgl Kirim Retur',
            'Catatan',
        ];
    }

    public function map($return): array
    {
        $settlement = $return->settlement;
        $refunds = $settlement?->refunds ?? collect();

        $timezone = (string) config('app.business_timezone', 'Asia/Jakarta');

        return [
            $return->return_number,
            $return->order?->salesorder_no,
            $return->order?->channel_order_no,
            $return->customer_name ?? $return->order?->customer_name,
            $return->source,
            $return->status,
            $return->reason,
            SalesReturn::REASON_CATEGORY_LABELS[$return->reason_category] ?? $return->reason_category,
            SalesReturn::MP_DECISION_LABELS[$return->marketplace_decision] ?? $return->marketplace_decision,
            $return->channel_reason_text ?? $return->channel_reason_code,
            $return->location?->location_name,
            $return->processed_by,
            optional($return->processed_at)?->timezone($timezone)->format('Y-m-d H:i:s'),
            optional($return->created_at)?->timezone($timezone)->format('Y-m-d H:i:s'),
            $settlement?->settlement_number,
            $settlement?->status,
            $settlement ? (float) $settlement->total_amount : 0,
            (float) $refunds->sum('amount'),
            $refunds->count(),
            $refunds->pluck('refund_method')->unique()->implode(', '),
            optional($refunds->max('refund_date'))?->format('Y-m-d'),
            $return->refund_amount !== null ? (float) $return->refund_amount : null,
            $return->shipping_fee_original !== null ? (float) $return->shipping_fee_original : null,
            $return->shipping_fee_return !== null ? (float) $return->shipping_fee_return : null,
            ($return->shipping_fee_original !== null && $return->shipping_fee_return !== null)
                ? (float) $return->shipping_fee_return - (float) $return->shipping_fee_original
                : null,
            $return->return_tracking_number,
            $return->return_carrier,
            optional($return->return_shipped_at)?->timezone($timezone)->format('Y-m-d H:i:s'),
            $return->notes,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
