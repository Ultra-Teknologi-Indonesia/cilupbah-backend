<?php

namespace Modules\Report\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Report\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransferReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnFormatting, ShouldAutoSize
{
    private const DATE_FORMAT = 'dd/mm/yyyy hh:mm';

    private readonly bool $isMasuk;

    public function __construct(
        private readonly ReportService $reportService,
        private readonly array $filters,
    ) {
        $this->isMasuk = ($filters['jenis'] ?? 'keluar') === 'masuk';
    }

    public function collection(): Collection
    {
        return collect($this->reportService->transferReportRows($this->filters));
    }

    public function headings(): array
    {
        if ($this->isMasuk) {
            return [
                'No Terima',
                'Tanggal',
                'Tanggal Terima',
                'No Transfer Asal',
                'Lokasi Asal',
                'Lokasi Tujuan',
                'SKU',
                'Nama Barang',
                'Qty Diterima',
                'Catatan',
            ];
        }

        return [
            'No Transfer',
            'Tanggal',
            'Lokasi Asal',
            'Lokasi Tujuan',
            'SKU',
            'Nama Barang',
            'Qty',
            'Catatan',
        ];
    }

    public function map($row): array
    {
        $r = (array) $row;

        if ($this->isMasuk) {
            return [
                $r['no_terima'] ?? null,
                $this->toDate($r['tanggal'] ?? null),
                $this->toDate($r['tanggal_terima'] ?? null),
                $r['no_transfer_asal'] ?? null,
                $r['lokasi_asal'] ?? null,
                $r['lokasi_tujuan'] ?? null,
                $r['sku'] ?? null,
                $r['nama_barang'] ?? null,
                $r['qty'] ?? 0,
                $r['catatan'] ?? null,
            ];
        }

        return [
            $r['no_transfer'] ?? null,
            $this->toDate($r['tanggal'] ?? null),
            $r['lokasi_asal'] ?? null,
            $r['lokasi_tujuan'] ?? null,
            $r['sku'] ?? null,
            $r['nama_barang'] ?? null,
            $r['qty'] ?? 0,
            $r['catatan'] ?? null,
        ];
    }

    public function columnFormats(): array
    {
        return $this->isMasuk
            ? ['B' => self::DATE_FORMAT, 'C' => self::DATE_FORMAT, 'I' => NumberFormat::FORMAT_NUMBER]
            : ['B' => self::DATE_FORMAT, 'G' => NumberFormat::FORMAT_NUMBER];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function toDate($value): ?float
    {
        return $value ? ExcelDate::PHPToExcel(Carbon::parse($value)) : null;
    }
}
