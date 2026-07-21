<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;

class PutawayPerformanceReportService
{
    private const DETAIL_COLUMNS = [
        ['key' => 'tanggal', 'label' => 'Tanggal Transaksi'],
        ['key' => 'no_transaksi', 'label' => 'No Transaksi'],
        ['key' => 'qty', 'label' => 'Quantity', 'align' => 'right'],
        ['key' => 'durasi_per_sku', 'label' => 'Rata-rata Durasi Per SKU', 'align' => 'center'],
    ];

    private const SUMMARY_COLUMNS = [
        ['key' => 'total_transaksi', 'label' => 'Total Transaksi', 'align' => 'right'],
        ['key' => 'total_quantity', 'label' => 'Total Quantity', 'align' => 'right'],
        ['key' => 'durasi_per_transaksi', 'label' => 'Rata-rata Durasi Per Transaksi', 'align' => 'center'],
    ];

    public function __construct(
        protected ReportRepository $repository,
    ) {}

    public function build(bool $detail, array $filters)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->putawayPerformanceRows($filters));

        $pdf = Pdf::loadView('report::pdf.order-performance', [
            'title' => 'Laporan Performa Penempatan' . ($detail ? ' - Detail' : ''),
            'periode' => $this->periodeLabel($filters),
            'detail' => $detail,
            'columns' => self::DETAIL_COLUMNS,
            'summaryColumns' => self::SUMMARY_COLUMNS,
            'secondaryLabel' => 'Nama Pengguna',
            'summaryFirstLabel' => 'Nama Pengguna',
            'summaryGroupLabel' => 'Lokasi Gudang',
            'detailTotals' => true,
            'groups' => $detail ? $this->detailGroups($rows) : $this->summaryGroups($rows),

            'grandTotal' => null,
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    private function periodeLabel(array $filters): string
    {
        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->format('d M Y') : '-';

        return $fmt($filters['from'] ?? null) . ' - ' . $fmt($filters['to'] ?? null);
    }

    private function detailGroups(Collection $rows): array
    {
        return $rows
            ->groupBy(fn ($r) => $r->lokasi ?: '(tanpa lokasi)')
            ->map(fn (Collection $perLokasi, $lokasi) => [
                'lokasi' => $lokasi,
                'sub' => $perLokasi
                    ->groupBy(fn ($r) => $r->grup ?: '-')
                    ->map(fn (Collection $perUser, $user) => [
                        'nama' => $user,
                        'rows' => $perUser->map(fn ($r) => [
                            'tanggal' => $r->tanggal_raw ? Carbon::parse($r->tanggal_raw)->format('d M Y H.i') : '-',
                            'no_transaksi' => $r->no_transaksi,
                            'qty' => number_format((float) $r->qty, 0, ',', '.'),
                            'durasi_per_sku' => OrderPerformanceReportService::formatDuration(
                                $this->perSkuSeconds($r),
                                withSeconds: false,
                            ),
                        ])->all(),
                        'total' => $this->detailTotal($perUser),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function perSkuSeconds(object $r): float
    {
        $skuCount = max(1, (int) ($r->sku_count ?? 1));

        return (float) ($r->durasi_detik ?? 0) / $skuCount;
    }

    private function detailTotal(Collection $rows): array
    {
        $count = $rows->count();

        return [
            'no_transaksi' => number_format($count, 0, ',', '.'),
            'qty' => number_format($rows->sum(fn ($r) => (float) $r->qty), 0, ',', '.'),
            'durasi_per_sku' => OrderPerformanceReportService::formatDuration(
                $count > 0 ? $rows->sum(fn ($r) => $this->perSkuSeconds($r)) / $count : 0,
                withSeconds: false,
            ),
        ];
    }

    private function summaryGroups(Collection $rows): array
    {
        return $rows
            ->groupBy(fn ($r) => $r->lokasi ?: '(tanpa lokasi)')
            ->map(fn (Collection $perLokasi, $lokasi) => [
                'nama' => $lokasi,
                'rows' => $perLokasi
                    ->groupBy(fn ($r) => $r->grup ?: '-')
                    ->map(fn (Collection $perUser, $user) => ['nama' => $user] + $this->aggregate($perUser))
                    ->values()
                    ->all(),
                'total' => $this->aggregate($perLokasi),
            ])
            ->values()
            ->all();
    }

    private function aggregate(Collection $rows): array
    {
        $count = $rows->count();
        $durasi = $rows->sum(fn ($r) => (float) ($r->durasi_detik ?? 0));

        return [
            'total_transaksi' => number_format($count, 0, ',', '.'),
            'total_quantity' => number_format($rows->sum(fn ($r) => (float) $r->qty), 0, ',', '.'),
            'durasi_per_transaksi' => OrderPerformanceReportService::formatDuration(
                $count > 0 ? $durasi / $count : 0,
                withSeconds: false,
            ),
        ];
    }
}
