<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;
use Modules\Warehouse\Models\Location;

/**
 * Daftar Penempatan Barang — rincian isi tiap dokumen putaway pada satu tanggal
 * dan satu lokasi. Berbeda dari Laporan Performa Penempatan yang mengukur durasi;
 * laporan ini mendata barang, sumber penerimaan, dan rak tujuannya.
 */
class PutawayListReportService
{
    public function __construct(
        protected ReportRepository $repository,
    ) {}

    /** @return array<int, array{value: string, label: string}> */
    public function lookup(string $date, string $locationId): array
    {
        return $this->repository->putawayLookup($date, $locationId)
            ->map(fn ($p) => ['value' => $p->id, 'label' => $p->putaway_no])
            ->all();
    }

    public function build(string $date, string $locationId, array $putawayIds = [])
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->putawayItemRows($date, $locationId, $putawayIds));

        $pdf = Pdf::loadView('report::pdf.putaway-list', [
            'tanggal' => Carbon::parse($date)->format('d M Y'),
            'lokasi' => Location::find($locationId)?->location_name ?? '-',
            'documents' => $this->documents($rows),
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    /** Dikelompokkan per dokumen putaway; tiap dokumen punya blok kepala sendiri. */
    private function documents(Collection $rows): array
    {
        return $rows
            ->groupBy('putaway_id')
            ->map(function (Collection $items) {
                $first = $items->first();

                return [
                    'putaway_no' => $first->putaway_no,
                    'tanggal' => $first->tanggal
                        ? Carbon::parse($first->tanggal)->format('d M Y H.i')
                        : '-',
                    'runner' => $this->runnerLabel($first),
                    'rows' => $items->values()->map(fn ($r, $i) => [
                        'no' => $i + 1,
                        'sku' => $r->sku ?? '-',
                        'deskripsi' => $r->deskripsi ?? '-',
                        'serial_no' => $r->serial_no ?? '',
                        'batch_no' => $r->batch_no ?? '',
                        'sumber' => $r->sumber ?? '',
                        'kode_rak' => $r->kode_rak ?? '',
                        'qty' => number_format((float) $r->qty, 0, ',', '.'),
                    ])->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** Format Jubelio: "nama(email)"; tanpa email cukup namanya saja. */
    private function runnerLabel(object $row): string
    {
        $nama = $row->runner_name ?: '-';

        return $row->runner_email ? "{$nama}({$row->runner_email})" : $nama;
    }
}
