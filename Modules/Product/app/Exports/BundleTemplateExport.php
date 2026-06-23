<?php

namespace Modules\Product\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class BundleTemplateExport implements WithMultipleSheets
{
    public const COLUMNS = ['item_code', 'sku_composition', 'qty'];

    public function sheets(): array
    {
        $example = [
            ['BUNDLE-A', 'KP-HITAM-M', 2],
            ['BUNDLE-A', 'KP-PUTIH-L', 1],
        ];

        $instructions = [
            ['Kolom', 'Wajib', 'Keterangan'],
            ['item_code', 'Ya', 'SKU produk bundle (harus sudah ada di master).'],
            ['sku_composition', 'Ya', 'SKU komponen penyusun (harus sudah ada di master).'],
            ['qty', 'Ya', 'Jumlah komponen dalam bundle, integer >= 1.'],
        ];

        return [
            new TemplateDataSheet('Pengisian Data', self::COLUMNS, $example),
            new TemplateInstructionSheet('Petunjuk', $instructions),
        ];
    }
}
