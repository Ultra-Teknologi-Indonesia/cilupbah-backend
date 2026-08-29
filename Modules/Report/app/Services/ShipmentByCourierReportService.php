<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Support\EkspedisiNormalizer;
use Modules\Report\Support\SectionedReport;

class ShipmentByCourierReportService
{
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

        $rows = collect($this->repository->shipmentByCourierRows($filters))
            ->map(function ($r) {
                $r->ekspedisi = EkspedisiNormalizer::family($r->provider, $r->no_resi ?? null);

                return $r;
            });

        return [
            'view' => 'report::pdf.pengiriman-ekspedisi',
            'data' => [
                'title' => 'Laporan Pengiriman Berdasarkan Ekspedisi' . ($detail ? ' - Detail' : ''),
                'periode' => $this->periodeLabel($filters),
                'detail' => $detail,
                'groups' => $detail ? $this->detailGroups($rows) : null,
                'summary' => $detail ? null : $this->summaryRows($rows),
                'grandTotal' => $detail ? null : $this->total($rows),
            ],
        ];
    }

    public function sectioned(bool $detail, array $filters): SectionedReport
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->shipmentByCourierRows($filters))
            ->map(function ($r) {
                $r->ekspedisi = EkspedisiNormalizer::family($r->provider, $r->no_resi ?? null);

                return $r;
            });

        $report = SectionedReport::make(
            'Laporan Pengiriman Berdasarkan Ekspedisi' . ($detail ? ' - Detail' : ''),
            $this->periodeLabel($filters),
        );

        if ($rows->isEmpty()) {
            return $report->emptyNotice('Tidak ada pengiriman pada rentang tanggal ini.');
        }

        if ($detail) {
            $rows->groupBy('ekspedisi')->sortKeys()->each(function (Collection $items, $ekspedisi) use ($report) {
                $report->group("Nama Ekspedisi: {$ekspedisi}")
                    ->head(['Tanggal Transaksi', 'Kode Pengiriman', 'No Pesanan', 'No Resi', 'Quantity']);

                foreach ($items as $r) {
                    $report->row([
                        $r->tanggal ? Carbon::parse($r->tanggal)->format('d M Y H.i') : '-',
                        $r->kode_pengiriman ?? '',
                        $r->no_pesanan ?? '',
                        $r->no_resi ?? '',
                        (int) $r->qty,
                    ]);
                }

                $report->subtotal(['Total', '', '', '', (int) $items->sum(fn ($r) => (float) $r->qty)])
                    ->spacer();
            });

            return $report;
        }

        $report->head(['Nama Ekspedisi', 'Total Pesanan', 'Total Quantity']);
        $rows->groupBy('ekspedisi')->sortKeys()->each(function (Collection $items, $ekspedisi) use ($report) {
            $report->row([$ekspedisi, $items->count(), (int) $items->sum(fn ($r) => (float) $r->qty)]);
        });
        $report->grand(['Grand Total', $rows->count(), (int) $rows->sum(fn ($r) => (float) $r->qty)]);

        return $report;
    }

    private function periodeLabel(array $filters): string
    {
        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->format('d M Y') : '-';

        return $fmt($filters['from'] ?? null) . ' - ' . $fmt($filters['to'] ?? null);
    }

    private function detailGroups(Collection $rows): array
    {
        return $rows
            ->groupBy('ekspedisi')
            ->sortKeys()
            ->map(fn (Collection $items, $ekspedisi) => [
                'ekspedisi' => $ekspedisi,
                'rows' => $items->map(fn ($r) => [
                    'tanggal' => $r->tanggal ? Carbon::parse($r->tanggal)->format('d M Y H.i') : '-',
                    'kode_pengiriman' => $r->kode_pengiriman ?? '',
                    'no_pesanan' => $r->no_pesanan ?? '',
                    'no_resi' => $r->no_resi ?? '',
                    'qty' => number_format((float) $r->qty, 0, ',', '.'),
                ])->all(),
                'total' => $this->total($items),
            ])
            ->values()
            ->all();
    }

    private function summaryRows(Collection $rows): array
    {
        return $rows
            ->groupBy('ekspedisi')
            ->sortKeys()
            ->map(fn (Collection $items, $ekspedisi) => ['ekspedisi' => $ekspedisi] + $this->total($items))
            ->values()
            ->all();
    }

    private function total(Collection $rows): array
    {
        return [
            'total_pesanan' => number_format($rows->count(), 0, ',', '.'),
            'total_quantity' => number_format($rows->sum(fn ($r) => (float) $r->qty), 0, ',', '.'),
        ];
    }
}
