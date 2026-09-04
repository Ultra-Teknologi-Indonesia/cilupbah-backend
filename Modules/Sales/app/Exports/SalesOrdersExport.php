<?php

namespace Modules\Sales\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Sales\Models\SalesOrder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesOrdersExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly ?string $tab,
        private readonly ?string $dateFrom,
        private readonly ?string $dateTo,
        private readonly ?string $source,
        private readonly ?string $search,
        private readonly ?string $storeId,
        private readonly ?string $locationId,
    ) {}

    public function query(): Builder
    {
        $query = SalesOrder::query()
            ->with([
                'items:id,order_id,sku,qty_in_base',
                'location:id,location_name',
                'internalStore:id,name',
                'shop:shop_id,shop_name',
            ]);

        $this->applyTabFilter($query);

        if ($this->dateFrom) {
            $query->whereDate('transaction_date', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $query->whereDate('transaction_date', '<=', $this->dateTo);
        }
        if ($this->source) {
            $query->where('source', $this->source);
        }
        if ($this->storeId) {
            $query->where(function ($q) {
                $q->where('internal_store_id', $this->storeId)
                    ->orWhereHas('shop', fn ($s) => $s->where('id', $this->storeId));
            });
        }
        if ($this->locationId) {
            $query->where('location_id', $this->locationId);
        }
        if ($this->search) {
            $term = '%' . $this->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('salesorder_no', 'ilike', $term)
                    ->orWhere('customer_name', 'ilike', $term)
                    ->orWhere('channel_order_no', 'ilike', $term)
                    ->orWhere('tracking_number', 'ilike', $term);
            });
        }

        return $query->orderByDesc('transaction_date')->orderByDesc('created_at');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function collection(): Collection
    {
        return $this->query()->get();
    }

    public function headings(): array
    {
        return [
            'No. Pesanan',
            'No. Ref',
            'No. Channel',
            'Tanggal Transaksi',
            'Dibuat',
            'Status Internal',
            'Status Channel',
            'Sumber',
            'Toko',
            'Nama Pelanggan',
            'Nama Penerima',
            'No. Telp',
            'Alamat',
            'Kecamatan',
            'Kota',
            'Provinsi',
            'Kode Pos',
            'Lokasi (Gudang)',
            'Metode Kirim',
            'Kurir',
            'No. Resi',
            'Sudah Lunas',
            'COD',
            'Jumlah Item',
            'Total Qty',
            'Berat (gram)',
            'Sub Total',
            'Diskon',
            'Pajak',
            'Ongkos Kirim',
            'Asuransi',
            'Grand Total',
            'Catatan',
        ];
    }

    public function map($order): array
    {
        $storeName = $order->internalStore?->name ?? $order->shop?->shop_name ?? '';

        return [
            $order->salesorder_no,
            $order->no_ref,
            $order->channel_order_no,
            optional($order->transaction_date)?->format('Y-m-d H:i:s'),
            optional($order->created_at)?->format('Y-m-d H:i:s'),
            $this->statusLabel($order->status),
            $order->channel_status,
            $order->source ?? 'Manual',
            $storeName,
            $order->customer_name,
            $order->shipping_full_name,
            $order->shipping_phone,
            $order->shipping_address,
            $order->shipping_area,
            $order->shipping_city,
            $order->shipping_province,
            $order->shipping_post_code,
            $order->location?->location_name ?? '',
            $order->delivery_method,
            $order->shipping_provider,
            $order->tracking_number,
            $order->is_paid ? 'Ya' : 'Tidak',
            $order->is_cod ? 'Ya' : 'Tidak',
            $order->items->count(),
            (int) $order->items->sum('qty_in_base'),
            $order->order_weight_gram,
            $order->sub_total,
            $order->total_disc,
            $order->total_tax,
            $order->shipping_cost,
            $order->insurance_cost,
            $order->grand_total,
            $order->note,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function applyTabFilter($query): void
    {
        match ($this->tab) {
            'unpaid' => $query->where('status', 'pending')->where('is_paid', false),
            'ready-to-process' => $query->where('status', 'reserved'),
            'in-transit' => $query->where('status', 'shipped')->whereNull('received_date'),
            'completed' => $query->where('status', 'shipped')->whereNotNull('received_date'),
            'cancellation' => $query->where(fn ($q) => $q->where('is_canceled', true)->orWhereNotNull('cancel_requested_at')),
            'returned' => $query->whereHas('returns'),
            'empty-stock' => $query->where('status', 'reserved')->whereRaw(SalesOrder::shortfallItemWhereRaw()),
            'failed-pick' => $query->where('status', 'reserved')->whereNotNull('pick_failed_at'),
            default => null,
        };
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Menunggu',
            'reserved' => 'Siap Proses',
            'picked' => 'Dipicking',
            'packed' => 'Dipacking',
            'shipped' => 'Dikirim',
            'cancelled' => 'Dibatalkan',
            default => $status ?? '',
        };
    }
}
