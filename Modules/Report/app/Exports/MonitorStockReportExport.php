<?php

declare(strict_types=1);

namespace Modules\Report\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\MonitorStockRepository;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class MonitorStockReportExport implements FromQuery, WithColumnWidths, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private readonly MonitorStockRepository $monitorStock,
        private readonly InventoryMovementRepository $movements,
        private readonly array $params,
    ) {}

    public function query()
    {
        return $this->reportQuery();
    }

    public function title(): string
    {
        return 'Monitor Stok';
    }

    public function headings(): array
    {
        return $this->isChronology()
            ? ['Tanggal', 'SKU', 'Nama Produk', 'Lokasi', 'Rak', 'No. Transaksi', 'Referensi', 'Sumber', 'Arah', 'Qty', 'Saldo', 'Dibuat Oleh']
            : match ($this->tab()) {
                'gagal-sync' => ['Produk', 'SKU', 'Channel', 'Toko', 'Status', 'Pesan Error', 'Terakhir Sync', 'ID Produk Eksternal'],
                'tidak-laku' => ['Produk', 'SKU', 'Stok Tersedia', 'Qty Terjual', 'Hari Tidak Terjual', 'Penjualan/Hari', 'Terakhir Terjual'],
                'paling-laku' => ['Produk', 'SKU', 'Stok Tersedia', 'Qty Terjual', 'Penjualan/Hari', 'Terakhir Terjual'],
                'perkiraan-habis' => ['Produk', 'SKU', 'Stok Tersedia', 'Qty Terjual', 'Penjualan/Hari', 'Hari Menuju Habis', 'Perkiraan Habis'],
                default => ['Produk', 'SKU', 'Variasi', 'Stok Minimum', 'Stok Aman', 'On Hand', 'On Order', 'Tersedia', 'Qty Restock', 'Pesanan Aktif'],
            };
    }

    public function map($row): array
    {
        if ($this->isChronology()) {
            return [
                optional($row->transaction_date)->format('Y-m-d H:i:s') ?? (string) ($row->transaction_date ?? '-'),
                $row->product_sku ?? $row->product?->sku ?? '-',
                $row->product_name ?? $row->product?->product?->name ?? '-',
                $row->location_name ?? $row->location?->location_name ?? '-',
                $row->bin_code ?? $row->bin?->bin_final_code ?? '-',
                $row->transaction_number ?? '-',
                $row->ref_no ?? '-',
                $row->putaway_source_type ?? $row->source ?? '-',
                $this->direction((int) ($row->qty ?? 0)),
                (int) ($row->qty ?? 0),
                (int) ($row->total_balance ?? $row->balance ?? 0),
                $row->created_by ?? '-',
            ];
        }

        if ($this->tab() === 'gagal-sync') {
            return [
                $row->export_product_name ?? $row->product?->name ?? '-',
                $row->export_product_sku ?? $row->product?->sku ?? '-',
                $row->export_channel_name ?? $row->channelShop?->channel?->name ?? '-',
                $row->export_shop_name ?? $row->channelShop?->shop_name ?? '-',
                $row->sync_status ?? '-',
                $row->error_message ?? '-',
                optional($row->last_synced_at)->format('Y-m-d H:i:s') ?? '-',
                $row->external_product_id ?? '-',
            ];
        }

        $base = [
            $row->product_name ?? '-',
            $row->sku ?? '-',
            $row->variation_text ?? '-',
        ];

        if ($this->tab() === 'tidak-laku') {
            return [...$base, (int) ($row->total_available ?? 0), (int) ($row->qty_sold ?? 0), (int) ($row->days_idle ?? 0), (float) ($row->avg_per_day ?? 0), $row->last_sold ?? '-'];
        }

        if ($this->tab() === 'paling-laku') {
            return [...$base, (int) ($row->total_available ?? 0), (int) ($row->qty_sold ?? 0), (float) ($row->avg_per_day ?? 0), $row->last_sold ?? '-'];
        }

        if ($this->tab() === 'perkiraan-habis') {
            $daysToOut = $row->days_to_out !== null ? (int) $row->days_to_out : null;

            return [...$base, (int) ($row->total_available ?? 0), (int) ($row->qty_sold ?? 0), (float) ($row->avg_per_day ?? 0), $daysToOut ?? '-', $daysToOut !== null ? now()->addDays($daysToOut)->toDateString() : '-'];
        }

        $minStock = (int) ($row->min_stock ?? 0);
        $safeStock = (int) ($row->safe_stock ?? 0);
        $available = (int) ($row->total_available ?? 0);

        return [...$base, $minStock, $safeStock, (int) ($row->total_on_hand ?? 0), (int) ($row->total_on_order ?? 0), $available, max(0, ($safeStock > 0 ? $safeStock : $minStock) - $available), $row->pending_order_nos ?? '-'];
    }

    public function columnWidths(): array
    {
        return $this->isChronology()
            ? ['A' => 20, 'B' => 24, 'C' => 38, 'D' => 22, 'E' => 18, 'F' => 24, 'G' => 24, 'H' => 18, 'I' => 12, 'J' => 12, 'K' => 12, 'L' => 24]
            : ['A' => 38, 'B' => 24, 'C' => 28, 'D' => 16, 'E' => 16, 'F' => 16, 'G' => 16, 'H' => 16, 'I' => 16, 'J' => 38];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = $this->isChronology() ? 'L' : ($this->tab() === 'gagal-sync' ? 'H' : 'J');
        $sheet->freezePane('A2');
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);

        return [1 => ['font' => ['bold' => true]]];
    }

    public function reportQuery()
    {
        $tab = $this->tab();
        $filters = $this->params;

        $query = match ($tab) {
            'stok-kosong' => $this->monitorStock->modeQuery($this->params['mode'] ?? 'habis', $filters),
            'menipis' => $this->monitorStock->modeQuery('menipis', $filters),
            'sedang-dibeli' => $this->monitorStock->modeQuery('on-order', $filters),
            'tidak-laku' => $this->monitorStock->deadStockQuery($filters, max(1, (int) ($this->params['period'] ?? 90))),
            'paling-laku' => $this->monitorStock->fastMovingQuery($filters, max(1, (int) ($this->params['period'] ?? 30))),
            'perkiraan-habis' => $this->monitorStock->estimatedStockOutQuery($filters, 30, max(1, (int) ($this->params['period'] ?? 30))),
            'gagal-sync' => $this->monitorStock->failedSyncQuery($filters),
            'kronologi' => $this->movements->getHistoryQuery([
                ...$this->params,
                'view' => $this->params['kronologi_view'] ?? 'all',
                'source' => $this->params['kron_source'] ?? null,
                'direction' => $this->params['kron_direction'] ?? null,
            ]),
            default => throw new \InvalidArgumentException('Tab Monitor Stok tidak dikenal.'),
        };

        $query->withoutEagerLoads();

        return $query;
    }

    private function tab(): string
    {
        return (string) ($this->params['tab'] ?? 'stok-kosong');
    }

    private function isChronology(): bool
    {
        return $this->tab() === 'kronologi';
    }

    private function direction(int $qty): string
    {
        return $qty >= 0 ? 'Masuk' : 'Keluar';
    }
}
