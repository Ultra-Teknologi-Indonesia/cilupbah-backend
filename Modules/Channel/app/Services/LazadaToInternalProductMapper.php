<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;

class LazadaToInternalProductMapper
{
    public function map(array $lazadaProduct, string $shopId): array
    {
        $attributes = $lazadaProduct['attributes'] ?? [];

        $internal = [
            'category_id' => $this->resolveCategoryId($shopId, $lazadaProduct['primary_category'] ?? null),
            'name' => $attributes['name'] ?? 'Lazada Product',
            'description' => $attributes['description'] ?? '',
            'condition' => 'NEW',
            'is_draft' => strtolower((string) ($lazadaProduct['status'] ?? '')) !== 'active',
            'is_active' => true,
            'status' => Product::STATUS_MASTER,
            'is_from_channel' => true,
            'verified_at' => now(),
        ];

        $internal['media'] = [];
        foreach (array_values($lazadaProduct['images'] ?? []) as $idx => $url) {
            if (! $url) {
                continue;
            }
            $internal['media'][] = [
                'media_type' => 'image',
                'url' => $url,
                'is_primary' => $idx === 0,
                'sort_order' => $idx,
            ];
        }

        $jenisVariasi = $this->kumpulkanJenisVariasi($lazadaProduct['skus'] ?? []);
        $internal['variation_types'] = [];
        foreach (array_values($jenisVariasi) as $i => $label) {
            $internal['variation_types'][] = ['name' => $label, 'sort_order' => $i];
        }

        $internal['variants'] = [];
        foreach ($lazadaProduct['skus'] ?? [] as $skuData) {
            $sku = ! empty($skuData['SellerSku']) ? $skuData['SellerSku'] : null;

            $price = (float) ($skuData['special_price'] ?? 0) ?: (float) ($skuData['price'] ?? 0);

            $variant = [
                'sku' => $sku,
                'sell_price' => $price,
                'buy_price' => $price,
                'weight' => (float) ($skuData['package_weight'] ?? 0),
                'length' => (float) ($skuData['package_length'] ?? 0),
                'width' => (float) ($skuData['package_width'] ?? 0),
                'height' => (float) ($skuData['package_height'] ?? 0),
                'is_active' => strtolower((string) ($skuData['Status'] ?? 'active')) === 'active',
            ];

            $opsi = $this->opsiVarian($skuData, $jenisVariasi);
            if ($opsi) {
                $variant['options'] = $opsi;
            }

            $variant['media'] = $this->variantMedia($skuData['Images'] ?? []);
            if (empty($variant['media'])) {
                unset($variant['media']);
            }

            $internal['variants'][] = $variant;
        }

        if (empty($internal['variants'])) {
            $internal['variants'][] = [
                'sku' => null,
                'sell_price' => 0,
                'is_active' => true,
            ];
        }

        $internal['sku'] = null;
        $internal['length'] = $internal['variants'][0]['length'] ?? 0;
        $internal['width'] = $internal['variants'][0]['width'] ?? 0;
        $internal['height'] = $internal['variants'][0]['height'] ?? 0;

        $internal['channel_external_product_id'] = isset($lazadaProduct['item_id']) ? (string) $lazadaProduct['item_id'] : null;
        $internal['channel_shop_id_external'] = $shopId;

        return $internal;
    }

    /**
     * Lazada menaruh nilai variasi di saleProp, peta bebas seperti
     * ['color_family' => 'Black', 'size' => 'XL']. Kuncinya dikumpulkan dari
     * seluruh sku supaya urutan jenis variasi stabil untuk semua varian —
     * ProductService memasangkan opsi ke jenis variasi berdasarkan POSISI.
     *
     * @return array<string,string> kunci Lazada => label yang dipakai di sistem
     */
    protected function kumpulkanJenisVariasi(array $skus): array
    {
        $jenis = [];

        foreach ($skus as $skuData) {
            foreach ($skuData['saleProp'] ?? [] as $kunci => $nilai) {
                if (! is_string($kunci) || ! is_scalar($nilai) || trim((string) $nilai) === '') {
                    continue;
                }
                $jenis[$kunci] ??= $this->labelJenis($kunci);
            }
        }

        return $jenis;
    }

    /**
     * Satu entri per jenis variasi, urutannya sama untuk setiap varian. Varian
     * yang tidak punya nilai untuk satu jenis tetap dapat entri kosong supaya
     * posisinya tidak bergeser dan nilainya tidak mendarat di jenis yang salah.
     */
    protected function opsiVarian(array $skuData, array $jenisVariasi): array
    {
        if (! $jenisVariasi) {
            return [];
        }

        $opsi = [];
        foreach ($jenisVariasi as $kunci => $label) {
            $nilai = $skuData['saleProp'][$kunci] ?? null;
            $opsi[] = [
                'name' => $label,
                'value' => is_scalar($nilai) ? trim((string) $nilai) : '',
            ];
        }

        return $opsi;
    }

    protected function labelJenis(string $kunci): string
    {
        return match ($kunci) {
            'color_family' => 'Warna',
            'size' => 'Ukuran',
            default => ucfirst(str_replace('_', ' ', $kunci)),
        };
    }

    protected function variantMedia(array $images): array
    {
        $media = [];
        foreach (array_values($images) as $idx => $url) {
            if (! $url) {
                continue;
            }
            $media[] = [
                'media_type' => 'image',
                'url' => $url,
                'is_primary' => $idx === 0,
                'sort_order' => $idx,
            ];
        }

        return $media;
    }

    protected function resolveCategoryId(string $shopId, $lazadaCategoryId)
    {

        $fallback = function () {
            $id = DB::table('categories')
                ->where('name', 'Belum Dikategorikan')
                ->value('id');

            if ($id) {
                return $id;
            }

            return DB::table('categories')->insertGetId([
                'name' => 'Belum Dikategorikan',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        if (! $lazadaCategoryId) {
            return $fallback();
        }

        $channelId = DB::table('channel_shops')->where('shop_id', $shopId)->value('channel_id');
        if (! $channelId) {
            return $fallback();
        }

        $mappings = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->join('categories', 'categories.id', '=', 'category_channel_mappings.category_id')
            ->where('channel_categories.channel_id', $channelId)
            ->where('channel_categories.external_id', (string) $lazadaCategoryId)
            ->select('category_channel_mappings.category_id', 'categories.is_leaf')
            ->get();

        if ($mappings->isEmpty()) {
            return $fallback();
        }

        $leaves = $mappings->where('is_leaf', true);

        if ($leaves->count() === 1) {
            return (int) $leaves->first()->category_id;
        }

        if ($leaves->count() > 1) {
            return $fallback();
        }

        return (int) $mappings->first()->category_id;
    }
}
