<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Support\OrderPerformanceSpec;
use Modules\Report\Support\SectionedReport;

class OrderPerformanceReportService
{
    public function __construct(
        protected ReportRepository $repository,
    ) {}

    public static function formatDuration(int|float|null $seconds, bool $withSeconds = true): string
    {
        $total = max(0, (int) round((float) $seconds));
        $jam = intdiv($total, 3600);
        $menit = intdiv($total % 3600, 60);

        return $withSeconds
            ? sprintf('%d jam %d menit %d detik', $jam, $menit, $total % 60)
            : sprintf('%d jam %d menit', $jam, $menit);
    }

    public function build(string $type, bool $detail, array $filters)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->orderPerformanceRows($type, $filters));

        $data = [
            'title' => OrderPerformanceSpec::title($type, $detail),
            'periode' => $this->periodeLabel($filters),
            'type' => $type,
            'detail' => $detail,
            'columns' => OrderPerformanceSpec::detailColumns($type),
            'secondaryLabel' => OrderPerformanceSpec::secondaryGroupLabel($type),
            'summaryFirstLabel' => OrderPerformanceSpec::summaryFirstColumnLabel($type),
            'summaryGroupLabel' => OrderPerformanceSpec::summaryGroupLabel($type),
            'groups' => $detail ? $this->detailGroups($type, $rows) : $this->summaryGroups($type, $rows),
            'grandTotal' => $detail ? null : $this->aggregate($rows),
        ];

        $pdf = Pdf::loadView('report::pdf.order-performance', $data);
        $pdf->setPaper('a4', $type === OrderPerformanceSpec::PESANAN ? 'landscape' : 'portrait');

        return $pdf;
    }

    private function periodeLabel(array $filters): string
    {
        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->format('d M Y') : '-';

        return $fmt($filters['from'] ?? null) . ' - ' . $fmt($filters['to'] ?? null);
    }

    public function sectioned(string $type, bool $detail, array $filters): SectionedReport
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->orderPerformanceRows($type, $filters));

        $report = SectionedReport::make(
            OrderPerformanceSpec::title($type, $detail),
            $this->periodeLabel($filters),
        );

        if ($rows->isEmpty()) {
            return $report->emptyNotice('Tidak ada data pada rentang tanggal ini.');
        }

        return $detail
            ? $this->sectionedDetail($report, $type, $rows)
            : $this->sectionedSummary($report, $type, $rows);
    }

    private function sectionedDetail(SectionedReport $report, string $type, Collection $rows): SectionedReport
    {
        $columns = OrderPerformanceSpec::detailColumns($type);
        $headLabels = array_map(fn ($c) => $c['label'], $columns);
        $secondary = OrderPerformanceSpec::secondaryGroupLabel($type);

        $rows->groupBy(fn ($r) => $r->lokasi ?: '(tanpa lokasi)')
            ->each(function (Collection $perLokasi, $lokasi) use ($report, $type, $columns, $headLabels, $secondary) {
                $report->group("Lokasi Gudang: {$lokasi}");

                $perLokasi->groupBy(fn ($r) => $r->grup ?: '')
                    ->each(function (Collection $perGrup, $grup) use ($report, $type, $columns, $headLabels, $secondary) {
                        if ($secondary) {
                            $report->subgroup("{$secondary}: " . ($grup !== '' ? $grup : '-'));
                        }
                        $report->head($headLabels);

                        foreach ($perGrup as $r) {
                            $report->row(array_map(fn ($c) => $this->detailCell($type, $r, $c['key']), $columns));
                        }
                        $report->spacer();
                    });
            });

        return $report;
    }

    private function sectionedSummary(SectionedReport $report, string $type, Collection $rows): SectionedReport
    {
        $isKurir = $type === OrderPerformanceSpec::KURIR;
        $firstLabel = OrderPerformanceSpec::summaryFirstColumnLabel($type);
        $groupLabel = OrderPerformanceSpec::summaryGroupLabel($type);
        $head = [$firstLabel, 'Total Transaksi', 'Total Quantity', 'Durasi', 'Durasi Per Pesanan'];

        $outerKey = fn ($r) => ($isKurir ? $r->grup : $r->lokasi) ?: '(tanpa data)';
        $innerKey = fn ($r) => ($isKurir ? $r->lokasi : $r->grup) ?: '(tanpa data)';

        $rows->groupBy($outerKey)->each(function (Collection $outer, $nama) use ($report, $groupLabel, $head, $innerKey) {
            $report->group("{$groupLabel}: {$nama}")->head($head);

            $outer->groupBy($innerKey)->each(function (Collection $inner, $label) use ($report) {
                $agg = $this->rawAggregate($inner);
                $report->row([$label, $agg['total_transaksi'], $agg['total_quantity'], $agg['durasi'], $agg['durasi_per_pesanan']]);
            });

            $t = $this->rawAggregate($outer);
            $report->subtotal(['Total', $t['total_transaksi'], $t['total_quantity'], $t['durasi'], $t['durasi_per_pesanan']])
                ->spacer();
        });

        $g = $this->rawAggregate($rows);
        $report->grand(['Grand Total', $g['total_transaksi'], $g['total_quantity'], $g['durasi'], $g['durasi_per_pesanan']]);

        return $report;
    }

    private function detailCell(string $type, object $r, string $key): int|string
    {
        return match ($key) {
            'tanggal' => $r->tanggal_raw ? Carbon::parse($r->tanggal_raw)->format('d M Y H.i') : '-',
            'no_transaksi' => $r->no_transaksi ?? '-',
            'qty' => (int) ($r->qty ?? 0),
            'no_pesanan' => $r->no_pesanan ?? '-',
            'no_resi' => $r->no_resi ?? '-',
            'sku' => $r->sku ?? '-',
            'durasi' => self::formatDuration($r->durasi_detik ?? 0),
            'durasi_proses' => self::formatDuration($r->durasi_proses_detik ?? 0),
            'durasi_penugasan_pick' => self::formatDuration($r->durasi_penugasan_pick_detik ?? 0),
            'durasi_pick' => self::formatDuration($r->durasi_pick_detik ?? 0),
            'durasi_pack' => self::formatDuration($r->durasi_pack_detik ?? 0),
            'durasi_ship' => self::formatDuration($r->durasi_ship_detik ?? 0),
            'durasi_selesai' => self::formatDuration($r->durasi_selesai_detik ?? 0),
            default => '',
        };
    }

    private function rawAggregate(Collection $rows): array
    {
        $perTransaksi = $rows->groupBy('transaksi_id');
        $durasi = $perTransaksi->sum(fn (Collection $g) => (float) ($g->first()->durasi_detik ?? 0));
        $totalTransaksi = $perTransaksi->count();

        return [
            'total_transaksi' => $totalTransaksi,
            'total_quantity' => (int) $rows->sum(fn ($r) => (float) ($r->qty ?? 0)),
            'durasi' => self::formatDuration($durasi),
            'durasi_per_pesanan' => self::formatDuration($totalTransaksi > 0 ? $durasi / $totalTransaksi : 0),
        ];
    }

    private function detailGroups(string $type, Collection $rows): array
    {
        return $rows
            ->groupBy(fn ($r) => $r->lokasi ?: '(tanpa lokasi)')
            ->map(fn (Collection $perLokasi, $lokasi) => [
                'lokasi' => $lokasi,
                'sub' => $perLokasi
                    ->groupBy(fn ($r) => $r->grup ?: '')
                    ->map(fn (Collection $perGrup, $grup) => [
                        'nama' => $grup,
                        'rows' => $perGrup->map(fn ($r) => $this->detailRow($type, $r))->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function detailRow(string $type, object $r): array
    {
        $base = [
            'tanggal' => $r->tanggal_raw ? Carbon::parse($r->tanggal_raw)->format('d M Y H.i') : '-',
            'no_transaksi' => $r->no_transaksi,
            'qty' => number_format((float) ($r->qty ?? 0), 0, ',', '.'),
            'durasi' => self::formatDuration($r->durasi_detik ?? 0),
        ];

        if ($type === OrderPerformanceSpec::PESANAN) {
            return $base + [
                'durasi_proses' => self::formatDuration($r->durasi_proses_detik ?? 0),
                'durasi_penugasan_pick' => self::formatDuration($r->durasi_penugasan_pick_detik ?? 0),
                'durasi_pick' => self::formatDuration($r->durasi_pick_detik ?? 0),
                'durasi_pack' => self::formatDuration($r->durasi_pack_detik ?? 0),
                'durasi_ship' => self::formatDuration($r->durasi_ship_detik ?? 0),
                'durasi_selesai' => self::formatDuration($r->durasi_selesai_detik ?? 0),
            ];
        }

        if ($type === OrderPerformanceSpec::PACKER) {
            return $base + [
                'no_pesanan' => $r->no_pesanan ?? '-',
                'no_resi' => $r->no_resi ?? '-',
                'sku' => $r->sku ?? '-',
            ];
        }

        return $base;
    }

    private function summaryGroups(string $type, Collection $rows): array
    {
        $isKurir = $type === OrderPerformanceSpec::KURIR;
        $outerKey = fn ($r) => ($isKurir ? $r->grup : $r->lokasi) ?: '(tanpa data)';
        $innerKey = fn ($r) => ($isKurir ? $r->lokasi : $r->grup) ?: '(tanpa data)';

        return $rows
            ->groupBy($outerKey)
            ->map(fn (Collection $outer, $nama) => [
                'nama' => $nama,
                'rows' => $outer
                    ->groupBy($innerKey)
                    ->map(fn (Collection $inner, $label) => ['nama' => $label] + $this->aggregate($inner))
                    ->values()
                    ->all(),
                'total' => $this->aggregate($outer),
            ])
            ->values()
            ->all();
    }

    private function aggregate(Collection $rows): array
    {
        $perTransaksi = $rows->groupBy('transaksi_id');
        $durasi = $perTransaksi->sum(fn (Collection $g) => (float) ($g->first()->durasi_detik ?? 0));
        $totalTransaksi = $perTransaksi->count();

        return [
            'total_transaksi' => number_format($totalTransaksi, 0, ',', '.'),
            'total_quantity' => number_format($rows->sum(fn ($r) => (float) ($r->qty ?? 0)), 0, ',', '.'),
            'durasi' => self::formatDuration($durasi),
            'durasi_per_pesanan' => self::formatDuration($totalTransaksi > 0 ? $durasi / $totalTransaksi : 0),
        ];
    }
}
