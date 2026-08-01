<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Support\SectionedReport;

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
        $payload = $this->pdfPayload($detail, $filters);

        $pdf = Pdf::loadView($payload['view'], $payload['data']);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    public function pdfPayload(bool $detail, array $filters): array
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->putawayPerformanceRows($filters));

        return [
            'view' => 'report::pdf.order-performance',
            'data' => [
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
            ],
        ];
    }

    private function periodeLabel(array $filters): string
    {
        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->format('d M Y') : '-';

        return $fmt($filters['from'] ?? null) . ' - ' . $fmt($filters['to'] ?? null);
    }

    public function sectioned(bool $detail, array $filters): SectionedReport
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->putawayPerformanceRows($filters));

        $report = SectionedReport::make(
            'Laporan Performa Penempatan' . ($detail ? ' - Detail' : ''),
            $this->periodeLabel($filters),
        );

        if ($rows->isEmpty()) {
            return $report->emptyNotice('Tidak ada data pada rentang tanggal ini.');
        }

        if ($detail) {
            $rows->groupBy(fn ($r) => $r->lokasi ?: '(tanpa lokasi)')
                ->each(function (Collection $perLokasi, $lokasi) use ($report) {
                    $report->group("Lokasi Gudang: {$lokasi}");

                    $perLokasi->groupBy(fn ($r) => $r->grup ?: '-')
                        ->each(function (Collection $perUser, $user) use ($report) {
                            $report->subgroup('Nama Pengguna: ' . ($user !== '' ? $user : '-'))
                                ->head(['Tanggal Transaksi', 'No Transaksi', 'Quantity', 'Rata-rata Durasi Per SKU']);

                            foreach ($perUser as $r) {
                                $report->row([
                                    $r->tanggal_raw ? Carbon::parse($r->tanggal_raw)->format('d M Y H.i') : '-',
                                    $r->no_transaksi,
                                    (int) $r->qty,
                                    OrderPerformanceReportService::formatDuration($this->perSkuSeconds($r), withSeconds: false),
                                ]);
                            }

                            $count = $perUser->count();
                            $report->subtotal([
                                'Total',
                                $count,
                                (int) $perUser->sum(fn ($r) => (float) $r->qty),
                                OrderPerformanceReportService::formatDuration(
                                    $count > 0 ? $perUser->sum(fn ($r) => $this->perSkuSeconds($r)) / $count : 0,
                                    withSeconds: false,
                                ),
                            ])->spacer();
                        });
                });

            return $report;
        }

        $rows->groupBy(fn ($r) => $r->lokasi ?: '(tanpa lokasi)')
            ->each(function (Collection $perLokasi, $lokasi) use ($report) {
                $report->group("Lokasi Gudang: {$lokasi}")
                    ->head(['Nama Pengguna', 'Total Transaksi', 'Total Quantity', 'Rata-rata Durasi Per Transaksi']);

                $perLokasi->groupBy(fn ($r) => $r->grup ?: '-')
                    ->each(function (Collection $perUser, $user) use ($report) {
                        $agg = $this->rawAggregate($perUser);
                        $report->row([
                            $user !== '' ? $user : '-',
                            $agg['total_transaksi'],
                            $agg['total_quantity'],
                            $agg['durasi_per_transaksi'],
                        ]);
                    });

                $sub = $this->rawAggregate($perLokasi);
                $report->subtotal(['Total', $sub['total_transaksi'], $sub['total_quantity'], $sub['durasi_per_transaksi']])
                    ->spacer();
            });

        return $report;
    }

    private function rawAggregate(Collection $rows): array
    {
        $count = $rows->count();
        $durasi = $rows->sum(fn ($r) => (float) ($r->durasi_detik ?? 0));

        return [
            'total_transaksi' => $count,
            'total_quantity' => (int) $rows->sum(fn ($r) => (float) $r->qty),
            'durasi_per_transaksi' => OrderPerformanceReportService::formatDuration(
                $count > 0 ? $durasi / $count : 0,
                withSeconds: false,
            ),
        ];
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
