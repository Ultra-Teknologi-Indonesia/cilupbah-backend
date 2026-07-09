<?php

namespace Modules\Sales\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Outbound\Models\Courier;
use Modules\Sales\Models\InternalStore;
use Modules\Warehouse\Models\Location;

class SalesOrderImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new SoTemplateDataSheet(),
            new SoTemplateInstructionSheet(),
            new SoTemplateMasterDataSheet(),
        ];
    }
}

class SoTemplateDataSheet implements FromArray, WithHeadings, WithTitle
{
    public const HEADER_COLUMNS = [
        'No. Pesanan',
        'No. Ref',
        'Tanggal',
        'Nama Pelanggan',
        'Toko',
        'Lokasi',
        'Salesman',
        'Sudah Lunas',
        'COD',
        'Harga Termasuk Pajak',
        'Nama Penerima',
        'Alamat Lengkap',
        'Kecamatan',
        'Kota',
        'Provinsi',
        'Kode Pos',
        'No. Telp Penerima',
        'Kurir',
        'No. Resi',
        'Ongkos Kirim',
        'Diskon Ongkir',
        'Asuransi',
        'Diskon Lainnya',
        'Biaya Proses',
        'Berat (gram)',
        'Keterangan',
        'SKU',
        'Harga',
        'Qty',
        'Nilai Diskon',
        'Pajak (Nominal)',
    ];

    public function array(): array
    {
        return [
            [
                '[auto]', '', '2026-07-09', 'John Doe', '', '', '',
                'TRUE', 'FALSE', 'FALSE',
                'John Doe', 'Jl. Contoh No. 1', 'Neglasari', 'Tangerang', 'Banten', '15121',
                '081112345678', '', '', 0, 0, 0, 0, 0, 500,
                'Pesanan contoh',
                'SKU-001', 50000, 2, 0, 0,
            ],
            [
                '', '', '', '', '', '', '',
                '', '', '',
                '', '', '', '', '', '',
                '', '', '', '', '', '', '', '', '',
                '',
                'SKU-002', 30000, 1, 5000, 0,
            ],
        ];
    }

    public function headings(): array
    {
        return self::HEADER_COLUMNS;
    }

    public function title(): string
    {
        return 'Data Pesanan';
    }
}

class SoTemplateInstructionSheet implements FromArray, WithTitle
{
    public function array(): array
    {
        return [
            ['Kolom', 'Wajib', 'Keterangan'],
            ['No. Pesanan', 'Tidak', 'Kunci grup. Isi [auto] atau kosongkan untuk nomor otomatis. Baris lanjutan (item tambahan) = kosongkan.'],
            ['No. Ref', 'Tidak', 'Nomor referensi pelanggan, bebas.'],
            ['Tanggal', 'Ya (header)', 'Format YYYY-MM-DD atau DD-MM-YYYY.'],
            ['Nama Pelanggan', 'Ya (header)', 'Nama pelanggan.'],
            ['Toko', 'Ya (header)', 'Nama toko internal — lihat sheet Master Data.'],
            ['Lokasi', 'Ya (header)', 'Nama lokasi/gudang — lihat sheet Master Data.'],
            ['Salesman', 'Tidak', 'Nama salesman (opsional).'],
            ['Sudah Lunas', 'Tidak', 'TRUE atau FALSE.'],
            ['COD', 'Tidak', 'TRUE atau FALSE.'],
            ['Harga Termasuk Pajak', 'Tidak', 'TRUE atau FALSE (default FALSE).'],
            ['Nama Penerima', 'Tidak', 'Nama penerima (default = Nama Pelanggan).'],
            ['Alamat Lengkap', 'Tidak', 'Alamat lengkap penerima.'],
            ['Kecamatan', 'Tidak', 'Kecamatan penerima.'],
            ['Kota', 'Tidak', 'Kota penerima.'],
            ['Provinsi', 'Tidak', 'Provinsi penerima.'],
            ['Kode Pos', 'Tidak', 'Kode pos penerima.'],
            ['No. Telp Penerima', 'Tidak', 'Nomor telepon penerima.'],
            ['Kurir', 'Tidak', 'Nama kurir — lihat sheet Master Data. Kosong = Kirim Sendiri.'],
            ['No. Resi', 'Tidak', 'Nomor resi.'],
            ['Ongkos Kirim', 'Tidak', 'Angka (default 0).'],
            ['Diskon Ongkir', 'Tidak', 'Angka (default 0).'],
            ['Asuransi', 'Tidak', 'Angka (default 0).'],
            ['Diskon Lainnya', 'Tidak', 'Angka (default 0).'],
            ['Biaya Proses', 'Tidak', 'Angka (default 0).'],
            ['Berat (gram)', 'Tidak', 'Berat paket dalam gram.'],
            ['Keterangan', 'Tidak', 'Catatan pesanan.'],
            ['SKU', 'Ya', 'Kode SKU produk yang terdaftar dan aktif.'],
            ['Harga', 'Tidak', 'Harga per item. Kosong = pakai harga jual dari katalog.'],
            ['Qty', 'Ya', 'Jumlah item, minimal 1.'],
            ['Nilai Diskon', 'Tidak', 'Diskon nominal per baris item (default 0).'],
            ['Pajak (Nominal)', 'Tidak', 'Pajak nominal per baris item (default 0).'],
        ];
    }

    public function title(): string
    {
        return 'Petunjuk Pengisian';
    }
}

class SoTemplateMasterDataSheet implements FromArray, WithTitle
{
    public function array(): array
    {
        $couriers = Courier::where('is_active', true)->orderBy('name')->pluck('name')->all();
        $stores = InternalStore::where('is_active', true)->orderBy('name')->get(['name', 'code'])->map(fn ($s) => $s->code ? "{$s->name} ({$s->code})" : $s->name)->all();
        $locations = Location::where('is_active', true)->orderBy('location_name')->get(['location_name', 'location_code'])->map(fn ($l) => $l->location_code ? "{$l->location_name} ({$l->location_code})" : $l->location_name)->all();

        $maxRows = max(count($couriers), count($stores), count($locations), 1);
        $rows = [['Kurir', 'Toko', 'Lokasi (Gudang)']];

        for ($i = 0; $i < $maxRows; $i++) {
            $rows[] = [
                $couriers[$i] ?? '',
                $stores[$i] ?? '',
                $locations[$i] ?? '',
            ];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Master Data';
    }
}
