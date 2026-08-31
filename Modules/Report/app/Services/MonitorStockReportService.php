<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use App\Services\PdfRenderer;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Report\Exports\MonitorStockReportExport;

final class MonitorStockReportService
{
    public function __construct(
        private readonly MonitorStockRepository $monitorStock,
        private readonly InventoryMovementRepository $movements,
        private readonly PdfRenderer $pdfRenderer,
    ) {}

    public function export(array $params): MonitorStockReportExport
    {
        return new MonitorStockReportExport($this->monitorStock, $this->movements, $params);
    }

    public function pdfBytes(array $params): string
    {
        set_time_limit(600);

        $export = $this->export($params);
        $query = $export->reportQuery();
        $hasRows = (clone $query)->exists();
        $rows = $query->cursor()->map(fn ($row): array => $export->map($row));

        return $this->pdfRenderer->bytes('report::pdf.monitor-stock', [
            'title' => $this->title($params),
            'filters' => $this->filterLabel($params),
            'headings' => $export->headings(),
            'rows' => $rows,
            'hasRows' => $hasRows,
        ], 'a4', 'landscape');
    }

    private function title(array $params): string
    {
        return match ($params['tab'] ?? '') {
            'stok-kosong' => 'Monitor Stok - Stok Kosong',
            'menipis' => 'Monitor Stok - Menipis',
            'tidak-laku' => 'Monitor Stok - Tidak Laku',
            'paling-laku' => 'Monitor Stok - Paling Laku',
            'perkiraan-habis' => 'Monitor Stok - Perkiraan Habis',
            'sedang-dibeli' => 'Monitor Stok - Sedang Dibeli',
            'gagal-sync' => 'Monitor Stok - Gagal Sync',
            'kronologi' => 'Monitor Stok - Kronologi',
            default => 'Monitor Stok',
        };
    }

    private function filterLabel(array $params): string
    {
        $labels = [];
        if (! empty($params['search'])) {
            $labels[] = 'Pencarian: '.$params['search'];
        }
        if (! empty($params['location_id'])) {
            $labels[] = 'Lokasi: '.$params['location_id'];
        }
        if (! empty($params['category_id'])) {
            $labels[] = 'Kategori: '.$params['category_id'];
        }
        if (! empty($params['date_from']) || ! empty($params['date_to'])) {
            $labels[] = 'Tanggal: '.($params['date_from'] ?? 'awal').' s/d '.($params['date_to'] ?? 'akhir');
        }

        return $labels === [] ? 'Semua data sesuai akses pengguna' : implode(' · ', $labels);
    }
}
