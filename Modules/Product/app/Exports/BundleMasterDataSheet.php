<?php

namespace Modules\Product\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Product\Models\Category;
use Modules\Product\Models\ProductVariant;

class BundleMasterDataSheet implements FromArray, WithTitle
{
    public function array(): array
    {
        $categories = Category::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        $components = ProductVariant::with('product:id,name,is_bundle')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_bundle', false)->where('is_active', true))
            ->orderBy('sku')
            ->get(['sku', 'product_id', 'sell_price'])
            ->all();

        $maxRows = max(count($categories), count($components), 1);

        $rows = [[
            'ID Kategori',
            'Nama Kategori',
            'SKU Komponen (Aktif)',
            'Nama Produk Komponen',
            'Harga Jual Komponen',
        ]];

        for ($i = 0; $i < $maxRows; $i++) {
            $cat = $categories[$i] ?? null;
            $comp = $components[$i] ?? null;

            $rows[] = [
                $cat?->id ?? '',
                $cat?->name ?? '',
                $comp?->sku ?? '',
                $comp?->product?->name ?? '',
                $comp?->sell_price ?? '',
            ];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Master Data';
    }
}
