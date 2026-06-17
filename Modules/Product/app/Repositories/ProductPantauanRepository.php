<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Daftar produk untuk Pantauan dengan 4 lensa ketidakseragaman.
 * Lensa belum_upload & harga didukung penuh; sku & atribut menyusul (butuh
 * data channel yang disimpan di fase berikutnya).
 */
class ProductPantauanRepository
{
    public function paginate(string $lens): LengthAwarePaginator
    {
        $activeShopCount = ChannelShop::query()
            ->where('is_active', true)
            ->whereNull('disconnected_at')
            ->count();

        $notUploadedSub = ProductChannelMapping::query()
            ->selectRaw("{$activeShopCount} - count(distinct channel_shop_id)")
            ->whereColumn('product_id', 'products.id')
            ->where('sync_status', '<>', ProductChannelMapping::STATUS_DEACTIVATED);

        $query = QueryBuilder::for(Product::class)
            ->with(['category', 'media'])
            ->addSelect(['not_uploaded_count' => $notUploadedSub])
            ->allowedSearch('name', 'sku')
            ->allowedFilters(
                AllowedFilter::callback('category_id', fn ($q, $value) => $q->whereIn('category_id', $this->categoryWithDescendants($value))),
                AllowedFilter::callback('channel', fn ($q, $value) => $q->whereHas('channelMappings.channelShop.channel', fn ($c) => $c->where('code', $value))),
                AllowedFilter::callback('type', fn ($q, $value) => $this->filterType($q, $value)),
            )
            ->defaultSort('name');

        $this->applyLens($query, $lens, $activeShopCount);

        return $query->paginate(request('per_page', 25))->appends(request()->query());
    }

    private function applyLens($query, string $lens, int $activeShopCount): void
    {
        switch ($lens) {
            case 'belum_upload':
                // Produk yang belum terupload ke ≥1 toko aktif.
                $query->whereRaw(
                    "(select count(distinct channel_shop_id) from product_channel_mappings where product_id = products.id and sync_status <> ?) < ?",
                    [ProductChannelMapping::STATUS_DEACTIVATED, $activeShopCount]
                );
                break;

            case 'harga':
                // Harga berbeda antar channel (override), ATAU harga live channel
                // (synced_price) berbeda dari harga efektif master.
                $query->whereExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('product_variants as pv')
                        ->whereColumn('pv.product_id', 'products.id')
                        ->whereRaw('((select count(distinct coalesce(pvcm.override_price, pv.sell_price)) from product_variant_channel_mappings pvcm where pvcm.variant_id = pv.id) > 1 or exists (select 1 from product_variant_channel_mappings pvcm2 where pvcm2.variant_id = pv.id and pvcm2.synced_price is not null and pvcm2.synced_price <> coalesce(pvcm2.override_price, pv.sell_price)))');
                });
                break;

            case 'sku':
                // Seller SKU di channel berbeda dari SKU master.
                $query->whereExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('product_variants as pv')
                        ->join('product_variant_channel_mappings as pvcm', 'pvcm.variant_id', '=', 'pv.id')
                        ->whereColumn('pv.product_id', 'products.id')
                        ->whereNotNull('pvcm.channel_seller_sku')
                        ->whereColumn('pvcm.channel_seller_sku', '<>', 'pv.sku');
                });
                break;

            case 'atribut':
                // Atribut channel (kanonik) berbeda antar toko untuk produk yang sama.
                // Cast ke text karena tipe json Postgres tak punya operator distinct.
                $query->whereRaw(
                    "(select count(distinct channel_attributes::text) from product_channel_mappings where product_id = products.id and channel_attributes is not null) > 1"
                );
                break;

            default:
                break;
        }
    }

    private function filterType($query, $value)
    {
        return match ($value) {
            'bundle' => $query->where('is_bundle', true),
            'konsinyasi' => $query->where('is_consignment', true),
            'satuan' => $query
                ->where(fn ($w) => $w->whereNull('is_bundle')->orWhere('is_bundle', false))
                ->where(fn ($w) => $w->whereNull('is_consignment')->orWhere('is_consignment', false)),
            default => $query,
        };
    }

    private function categoryWithDescendants($values): array
    {
        $roots = array_filter(array_map('intval', (array) $values));
        if (empty($roots)) {
            return [0];
        }

        $childrenByParent = [];
        foreach (Category::query()->get(['id', 'parent_id']) as $cat) {
            $childrenByParent[(int) $cat->parent_id][] = (int) $cat->id;
        }

        $ids = [];
        $stack = $roots;
        while ($stack) {
            $cur = array_pop($stack);
            if (in_array($cur, $ids, true)) {
                continue;
            }
            $ids[] = $cur;
            foreach ($childrenByParent[$cur] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }
}
