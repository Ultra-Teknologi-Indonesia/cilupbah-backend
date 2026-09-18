<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use App\Services\PdfRenderer;
use App\Support\WarehouseAccess;
use Illuminate\Support\Collection;
use Modules\Inventory\Http\Resources\StockItemResource;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Warehouse\Models\Location;
use setasign\Fpdi\Fpdi;

final class StockPositionReportService
{
    public function __construct(
        private readonly InventoryRepository $inventory,
        private readonly PdfRenderer $pdfRenderer,
    ) {}

    public function writeCsv(array $params, string $targetPath): int
    {
        $handle = fopen($targetPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Tidak dapat membuka berkas CSV Posisi Stok.');
        }

        try {
            $locations = $this->locations($params);
            $this->writeCsvRow($handle, $this->headings($locations));
            $written = 0;

            foreach ($this->mappedRows($params, $locations) as $row) {
                $this->writeCsvRow($handle, $row);
                $written++;
            }

            if (! fflush($handle)) {
                throw new \RuntimeException('Tidak dapat menyelesaikan berkas CSV Posisi Stok.');
            }

            return $written;
        } finally {
            fclose($handle);
        }
    }

    public function writePdf(array $params, string $targetPath): void
    {
        set_time_limit((int) config('exports.timeout', 900));

        $locations = $this->locations($params);
        $headings = $this->headings($locations);
        $pdf = new Fpdi('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $rows = [];
        $hasRows = false;
        $chunkSize = max(25, (int) config('exports.pdf_chunk_size', 250));

        foreach ($this->mappedRows($params, $locations) as $row) {
            $rows[] = $row;
            $hasRows = true;

            if (count($rows) >= $chunkSize) {
                $this->appendPdfChunk($pdf, $params, $headings, $rows, true);
                $rows = [];
                gc_collect_cycles();
            }
        }

        if ($rows !== [] || ! $hasRows) {
            $this->appendPdfChunk($pdf, $params, $headings, $rows, $hasRows);
        }

        $pdf->Output('F', $targetPath);
    }

    private function appendPdfChunk(
        Fpdi $pdf,
        array $params,
        array $headings,
        array $rows,
        bool $hasRows,
    ): void {
        $chunkPath = tempnam(sys_get_temp_dir(), 'cilupbah-stock-position-pdf-');
        if ($chunkPath === false) {
            throw new \RuntimeException('Tidak dapat membuat bagian PDF Posisi Stok.');
        }

        try {
            $bytes = $this->pdfRenderer->bytes('report::pdf.stock-position', [
                'title' => 'Posisi Stok',
                'filters' => $this->filterLabel($params),
                'headings' => $headings,
                'rows' => $rows,
                'hasRows' => $hasRows,
            ], 'a4', 'landscape');

            if (file_put_contents($chunkPath, $bytes) === false) {
                throw new \RuntimeException('Tidak dapat menulis bagian PDF Posisi Stok.');
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

    private function mappedRows(array $params, Collection $locations): \Generator
    {
        foreach ($this->inventory->stockPositionExportChunks(
            $params,
            (int) config('exports.sheet_chunk_size', 250),
        ) as $items) {
            $resolved = StockItemResource::collectionWithTransit($items);

            foreach ($resolved as $item) {
                yield $this->map($item, $locations);
            }

            unset($resolved, $items);
            gc_collect_cycles();
        }
    }

    private function map(array $item, Collection $locations): array
    {
        $stocks = collect($item['location_stocks'] ?? [])->keyBy('location_id');
        $rows = [
            $item['item_name'] ?? '-',
            collect($item['variation_values'] ?? [])->pluck('value')->filter()->implode(', ') ?: '-',
            $item['item_code'] ?? '-',
            (float) ($item['average_cost'] ?? 0),
        ];

        foreach ($locations as $location) {
            $stock = $stocks->get((string) $location->id, []);
            $rows[] = (int) ($stock['on_hand'] ?? 0);
            $rows[] = (int) ($stock['on_order'] ?? 0);
            $rows[] = (int) ($stock['available'] ?? 0);
        }

        $total = $item['total_stocks'] ?? [];
        $rows[] = (int) ($total['transit'] ?? 0);
        $rows[] = (int) ($total['on_hand'] ?? 0);
        $rows[] = (int) ($total['on_order'] ?? 0);
        $rows[] = (int) ($total['available'] ?? 0);

        return $rows;
    }

    private function headings(Collection $locations): array
    {
        $headings = ['Produk', 'Variasi', 'SKU', 'Harga Pokok'];

        foreach ($locations as $location) {
            $name = (string) $location->location_name;
            $headings[] = "{$name} - On Hand";
            $headings[] = "{$name} - On Order";
            $headings[] = "{$name} - Avail";
        }

        return [...$headings, 'Transit', 'Total On Hand', 'Total On Order', 'Total Avail'];
    }

    private function locations(array $params): Collection
    {
        $allowed = $params['allowed_location_ids'] ?? WarehouseAccess::allowedIds();
        $requested = array_key_exists('visible_location_ids', $params)
            ? array_values(array_unique(array_map('strval', (array) $params['visible_location_ids'])))
            : null;

        $query = Location::query()
            ->where('is_active', true)
            ->where('location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->select(['id', 'location_name']);

        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }
        if ($requested !== null) {
            $query->whereIn('id', $requested);
        }

        $locations = $query->orderBy('location_name')->get();

        if ($requested === null) {
            return $locations;
        }

        $order = array_flip($requested);

        return $locations->sortBy(fn ($location) => $order[(string) $location->id] ?? PHP_INT_MAX)->values();
    }

    private function writeCsvRow($handle, array $row): void
    {
        if (fputcsv($handle, $row, ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('Tidak dapat menulis baris CSV Posisi Stok.');
        }
    }

    private function filterLabel(array $params): string
    {
        $labels = [];
        if (! empty($params['search'])) {
            $labels[] = 'Pencarian: '.$params['search'];
        }
        if (($params['is_bundle'] ?? null) !== null && $params['is_bundle'] !== '') {
            $labels[] = ((string) $params['is_bundle'] === '1') ? 'Bundle' : 'Satuan';
        }
        if (! empty($params['channel'])) {
            $labels[] = 'Channel: '.$params['channel'];
        }

        return $labels === [] ? 'Semua data sesuai akses pengguna' : implode(' · ', $labels);
    }
}
