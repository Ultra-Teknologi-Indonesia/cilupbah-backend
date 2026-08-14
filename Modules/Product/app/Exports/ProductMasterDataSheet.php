<?php

namespace Modules\Product\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Product\Models\Category;

class ProductMasterDataSheet implements FromArray, WithTitle
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
}
