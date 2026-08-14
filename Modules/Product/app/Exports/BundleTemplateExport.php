<?php

namespace Modules\Product\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class BundleTemplateExport implements WithMultipleSheets
{
    public const COLUMNS = [
        'item_code', 'bundle_name', 'category', 'sell_price', 'description', 'sku_composition', 'qty',
    ];

    public function sheets(): array
    {
        $example = [
            ['BUNDLE-PAKET-A', 'Paket Hemat Kaos Polos', 'Fashion Pria', 120000, 'Paket 2 kaos polos M & L', 'KP-HITAM-M', 2],
            ['BUNDLE-PAKET-A', 'Paket Hemat Kaos Polos', 'Fashion Pria', 120000, 'Paket 2 kaos polos M & L', 'KP-PUTIH-L', 1],
        ];

        $instructions = [
            ['Kolom', 'Wajib', 'Keterangan'],
            ['item_code', 'Ya', 'SKU produk bundle (unik). Jadi kunci pengenal bundle.'],
            ['bundle_name', 'Opsional', 'Nama produk bundle. Wajib diisi jika SKU bundle baru / belum ada di master produk.'],
            ['category', 'Opsional', 'Nama kategori bundle. Lihat sheet Master Data untuk pilihan nama kategori yang tersedia.'],
            ['sell_price', 'Opsional', 'Harga jual paket bundle dalam angka (>= 0).'],
            ['description', 'Opsional', 'Deskripsi produk bundle.'],
            ['sku_composition', 'Ya', 'SKU komponen penyusun. Harus SKU varian tunggal aktif di master. Lihat sheet Master Data.'],
            ['qty', 'Ya', 'Jumlah unit komponen dalam bundle, integer >= 1.'],
        ];

        return [
            new TemplateDataSheet('Pengisian Data Bundle', self::COLUMNS, $example),
            new TemplateInstructionSheet('Petunjuk', $instructions),
            new BundleMasterDataSheet(),
        ];
    }
}
