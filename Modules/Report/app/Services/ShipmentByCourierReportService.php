<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Support\EkspedisiNormalizer;

/**
 * Laporan Pengiriman Berdasarkan Ekspedisi — permintaan docx "laporan manifest
 * yang belum ada di jubelio". Merangkum pesanan per keluarga ekspedisi.
 *
 * Detail: per ekspedisi, satu baris tiap pesanan + baris Total.
 * Summary: satu baris tiap ekspedisi (jumlah pesanan & quantity) + Grand Total.
 */
class ShipmentByCourierReportService
{
    public function __construct(
        protected ReportRepository $repository,
    ) {}

    public function build(bool $detail, array $filters)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->shipmentByCourierRows($filters))
            ->map(function ($r) {
                $r->ekspedisi = EkspedisiNormalizer::family($r->provider);

                return $r;
            });

        $pdf = Pdf::loadView('report::pdf.pengiriman-ekspedisi', [
            'title' => 'Laporan Pengiriman Berdasarkan Ekspedisi' . ($detail ? ' - Detail' : ''),
            'periode' => $this->periodeLabel($filters),
            'detail' => $detail,
            'groups' => $detail ? $this->detailGroups($rows) : null,
            'summary' => $detail ? null : $this->summaryRows($rows),
            'grandTotal' => $detail ? null : $this->total($rows),
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    private function periodeLabel(array $filters): string
    {
        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->format('d M Y') : '-';

        return $fmt($filters['from'] ?? null) . ' - ' . $fmt($filters['to'] ?? null);
    }

    /** Ekspedisi diurut alfabet; baris di dalamnya menurun menurut tanggal. */
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
