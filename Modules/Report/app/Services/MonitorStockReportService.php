<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use App\Services\PdfRenderer;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Report\Exports\MonitorStockReportExport;
use setasign\Fpdi\Fpdi;

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
        $temporaryPath = tempnam(sys_get_temp_dir(), 'cilupbah-export-pdf-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Tidak dapat membuat berkas PDF sementara.');
        }

        try {
            $this->writePdf($params, $temporaryPath);
            $bytes = file_get_contents($temporaryPath);
            if ($bytes === false) {
                throw new \RuntimeException('Tidak dapat membaca berkas PDF sementara.');
            }

            return $bytes;
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function writePdf(array $params, string $targetPath): void
    {
        set_time_limit((int) config('exports.timeout', 900));

        $export = $this->export($params);
        $rows = [];
        $pdf = new Fpdi('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $hasRows = false;
        $chunkSize = (int) config('exports.pdf_chunk_size', 250);

        foreach ($export->reportQuery()->cursor() as $row) {
            $rows[] = $export->map($row);
            $hasRows = true;

            if (count($rows) >= $chunkSize) {
                $this->appendPdfChunk($pdf, $params, $export, $rows, true);
                $rows = [];
                gc_collect_cycles();
            }
        }

        if ($rows !== [] || ! $hasRows) {
            $this->appendPdfChunk($pdf, $params, $export, $rows, $hasRows);
        }

        $pdf->Output('F', $targetPath);
    }

    private function appendPdfChunk(
        Fpdi $pdf,
        array $params,
        MonitorStockReportExport $export,
        array $rows,
        bool $hasRows,
    ): void {
        $chunkPath = tempnam(sys_get_temp_dir(), 'cilupbah-pdf-chunk-');
        if ($chunkPath === false) {
            throw new \RuntimeException('Tidak dapat membuat bagian PDF sementara.');
        }

        try {
            $bytes = $this->pdfRenderer->bytes('report::pdf.monitor-stock', [
                'title' => $this->title($params),
                'filters' => $this->filterLabel($params),
                'headings' => $export->headings(),
                'rows' => $rows,
                'hasRows' => $hasRows,
            ], 'a4', 'landscape');

            if (file_put_contents($chunkPath, $bytes) === false) {
                throw new \RuntimeException('Tidak dapat menulis bagian PDF sementara.');
            }

            $pageCount = $pdf->setSourceFile($chunkPath);
            for ($page = 1; $page <= $pageCount; $page++) {
                $pdf->AddPage('L', 'A4');
                $pdf->useTemplate($pdf->importPage($page), 0, 0, 297, 210);
            }
        } finally {
            @unlink($chunkPath);
        }
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
