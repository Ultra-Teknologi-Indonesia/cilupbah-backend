<?php

namespace Modules\Report\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Daftar Pelanggan — 1 baris per kontak pelanggan (Contact type CUSTOMER/BOTH).
 * Kolom Kecamatan & Detail Sumber dihapus (tak ada di data kontak kita).
 */
class CustomerListExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithStyles, WithTitle
{
    public function __construct(
        private readonly Builder $query,
    ) {}

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return 'Data1';
    }

    public function headings(): array
    {
        return [
            'Nama Kontak',
            'Email',
            'No Telepon',
            'Alamat',
            'Kota',
            'Provinsi',
            'Kode Pos',
            'Tanggal Lahir',
            'Kewarganegaraan',
            'Tanggal Dibuat',
            'Sumber Pelanggan',
            'Kategori Pelanggan',
        ];
    }

    public function map($row): array
    {
        return [
            $row->name,
            $row->email ?? '',
            $row->phone ?: ($row->mobile ?? ''),
            $row->address ?? '',
            $row->city ?? '',
            $row->province ?? '',
            $row->postal_code ?? '',
            $row->birth_date ? Carbon::parse($row->birth_date)->format('Y-m-d') : '',
            $row->nationality ?? '',
            $row->created_at ? Carbon::parse($row->created_at)->format('Y-m-d H:i:s') : '',
            $row->source ?? '',
            $row->category_name ?? '',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 24, 'B' => 26, 'C' => 18, 'D' => 40, 'E' => 20, 'F' => 18,
            'G' => 10, 'H' => 14, 'I' => 16, 'J' => 20, 'K' => 16, 'L' => 20,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
