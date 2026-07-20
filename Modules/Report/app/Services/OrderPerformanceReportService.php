<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Support\OrderPerformanceSpec;

class OrderPerformanceReportService
{
    public function __construct(
        protected ReportRepository $repository,
    ) {}

    /**
     * "{j} jam {m} menit {d} detik", termasuk saat nol — mengikuti format Jubelio.
     * Nilai negatif dijepit nol; laporan performa yang menampilkan durasi minus
     * hanya membingungkan pembacanya.
     */
    public static function formatDuration(int|float|null $seconds): string
    {
        $total = max(0, (int) round((float) $seconds));

        return sprintf(
            '%d jam %d menit %d detik',
            intdiv($total, 3600),
            intdiv($total % 3600, 60),
            $total % 60,
        );
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

    /**
     * Detail: lokasi → grup (pengguna/kurir) → baris. Pesanan tidak punya grup kedua,
     * jadi seluruh barisnya langsung di bawah lokasi.
     */
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

        return $base + [
            'no_pesanan' => $r->no_pesanan ?? '-',
            'no_resi' => $r->no_resi ?? '-',
            'sku' => $r->sku ?? '-',
        ];
    }

    /**
     * Summary Kurir dikelompokkan per kurir dengan baris lokasi; jenis lain
     * dikelompokkan per lokasi dengan baris pengguna. Sumbunya sekadar bertukar.
     */
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

    /**
     * Durasi dijumlahkan per transaksi unik, bukan per baris — satu picklist yang
     * berisi 40 SKU tetap dihitung satu durasi, bukan 40 kali.
     */
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
