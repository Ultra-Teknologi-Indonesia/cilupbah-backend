<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Support\SectionedReport;
use Modules\Warehouse\Models\Location;

class PutawayListReportService
{
    public function __construct(
        protected ReportRepository $repository,
    ) {}

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

    /** Varian Excel "cermin PDF": satu blok per dokumen penempatan. */
    public function sectioned(string $date, string $locationId, array $putawayIds = []): SectionedReport
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $rows = collect($this->repository->putawayItemRows($date, $locationId, $putawayIds));

        $report = SectionedReport::make(
            'Daftar Penempatan Barang',
            sprintf(
                'Tanggal: %s   |   Lokasi: %s',
                Carbon::parse($date)->format('d M Y'),
                Location::find($locationId)?->location_name ?? '-',
            ),
        );

        if ($rows->isEmpty()) {
            return $report->emptyNotice('Tidak ada penempatan pada tanggal dan lokasi ini.');
        }

        $rows->groupBy('putaway_id')->each(function (Collection $items) use ($report) {
            $first = $items->first();

            $report->group(sprintf(
                'No. Putaway: %s   |   %s   |   Runner: %s',
                $first->putaway_no,
                $first->tanggal ? Carbon::parse($first->tanggal)->format('d M Y H.i') : '-',
                $this->runnerLabel($first),
            ))->head(['No', 'SKU', 'Deskripsi', 'Serial No', 'Batch No', 'Sumber', 'Kode Rak', 'Qty']);

            $no = 0;
            foreach ($items as $r) {
                $report->row([
                    (string) ++$no,
                    $r->sku ?? '-',
                    $r->deskripsi ?? '-',
                    $r->serial_no ?? '',
                    $r->batch_no ?? '',
                    $r->sumber ?? '',
                    $r->kode_rak ?? '',
                    (int) $r->qty,
                ]);
            }

            $report->spacer();
        });

        return $report;
    }

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

    private function runnerLabel(object $row): string
    {
        $nama = $row->runner_name ?: '-';

        return $row->runner_email ? "{$nama}({$row->runner_email})" : $nama;
    }
}
