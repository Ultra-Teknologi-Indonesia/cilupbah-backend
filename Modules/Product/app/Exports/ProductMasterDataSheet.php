<?php

namespace Modules\Product\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Modules\Product\Models\Category;

class ProductMasterDataSheet implements FromArray, WithTitle, ShouldAutoSize, WithStyles
{
    public function array(): array
    {
        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = [['ID Kategori', 'Nama Kategori']];

        foreach ($categories as $cat) {
            $rows[] = [
                $cat->id,
                $cat->name,
            ];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Master Data';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E3A8A'],
                ],
            ],
        ];
    }
}
