<?php

namespace Modules\Product\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProductTemplateExport implements WithMultipleSheets
{
    public const COLUMNS = [
        'item_group_name', 'item_code', 'sell_price', 'barcode',
        'item_category_id', 'category', 'description',
        'package_weight', 'package_length', 'package_width', 'package_height',
        'image_url1', 'image_url2', 'image_url3', 'image_url4', 'image_url5',
        'default_images',
    ];

    public function sheets(): array
    {
        $example = [[
            'Kaos Polos', 'KP-HITAM-M', 75000, '8991234567890',
            null, 'Fashion Pria', 'Kaos katun premium',
            0.2, 30, 25, 2,
            'https://contoh.com/img1.jpg', null, null, null, null,
            null,
        ]];

        $instructions = [
            ['Kolom', 'Wajib', 'Keterangan'],
            ['item_group_name', 'Ya', 'Nama produk. Baris dengan nama sama = varian dari satu produk.'],
            ['item_code', 'Ya', 'SKU varian (unik). Jadi kunci upsert varian.'],
            ['sell_price', 'Ya', 'Harga jual, angka >= 0.'],
            ['barcode', 'Tidak', 'Barcode varian.'],
            ['item_category_id', 'Tidak', 'ID kategori internal bila sudah tahu. Jika kosong, pakai kolom category.'],
            ['category', 'Tidak', 'Nama kategori; dibuat otomatis bila belum ada (default Uncategorized).'],
            ['description', 'Tidak', 'Deskripsi produk.'],
            ['package_weight', 'Tidak', 'Berat paket (angka).'],
            ['package_length', 'Tidak', 'Panjang paket (angka).'],
            ['package_width', 'Tidak', 'Lebar paket (angka).'],
            ['package_height', 'Tidak', 'Tinggi paket (angka).'],
            ['image_url1..5', 'Tidak', 'URL gambar produk (harus URL valid).'],
            ['default_images', 'Tidak', 'URL gambar default (harus URL valid).'],
        ];

        return [
            new TemplateDataSheet('Pengisian Import Produk', self::COLUMNS, $example),
            new TemplateInstructionSheet('Petunjuk', $instructions),
        ];
    }
}

class TemplateDataSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private string $title, private array $headings, private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}

class TemplateInstructionSheet implements FromArray, WithTitle
{
    public function __construct(private string $title, private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }
}
