<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductChannelValidation;
use Modules\Product\Models\ProductSyncLog;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductPantauanRepository
{
    public function paginate(string $lens): LengthAwarePaginator
    {
        $activeShopCount = ChannelShop::query()
            ->where('is_active', true)
            ->whereNull('disconnected_at')
            ->count();

        $query = QueryBuilder::for(Product::class)
            ->with(['category', 'media', 'channelValidations.channel'])
            // Jumlah toko aktif tempat produk sudah tersinkron (1 mapping per toko),
            // dihitung lewat withCount sehingga tidak perlu raw SQL.
            ->withCount(['channelMappings as synced_shop_count' => fn ($q) => $q->where('sync_status', '<>', ProductChannelMapping::STATUS_DEACTIVATED)])
            ->allowedSearch('name', 'sku')
            ->allowedFilters(
                AllowedFilter::callback('category_id', fn ($q, $value) => $q->whereIn('category_id', $this->categoryWithDescendants($value))),
                AllowedFilter::callback('channel', fn ($q, $value) => $q->whereHas('channelMappings.channelShop.channel', fn ($c) => $c->where('code', $value))),
                AllowedFilter::callback('type', fn ($q, $value) => $this->filterType($q, $value)),
            )
            ->defaultSort('name');

        if ($lens === 'gagal_upload') {
            $query->addSelect(['last_upload_error' => ProductSyncLog::query()
                ->select('error_message')
                ->whereColumn('product_id', 'products.id')
                ->where('action', ProductSyncLog::ACTION_UPLOAD)
                ->where('status', ProductSyncLog::STATUS_FAILED)
                ->latest()
                ->limit(1)]);
        }

        $this->applyLens($query, $lens, $activeShopCount);

        $paginator = $query->paginate(request('per_page', 25))->appends(request()->query());

        // Turunkan not_uploaded_count = total toko aktif - toko tersinkron, di PHP
        // (menghindari aritmetika di dalam kueri).
        $paginator->getCollection()->each(function (Product $product) use ($activeShopCount) {
            $product->not_uploaded_count = $activeShopCount - (int) $product->synced_shop_count;
        });

        return $paginator;
    }

    private function applyLens($query, string $lens, int $activeShopCount): void
    {
        switch ($lens) {
            case 'belum_upload':
                // Produk yang tersinkron ke kurang dari seluruh toko aktif
                // (1 mapping per toko, jadi count mapping non-deactivated = jumlah toko).
                $query->whereHas(
                    'channelMappings',
                    fn ($q) => $q->where('sync_status', '<>', ProductChannelMapping::STATUS_DEACTIVATED),
                    '<',
                    $activeShopCount
                );
                break;

            case 'harga':
                // Harga Tidak Cocok: status termaterialisasi di product_channel_validations
                // (synced_price marketplace <> coalesce(override_price, sell_price)).
                $this->whereMismatch($query, 'price_status');
                break;

            case 'sku':
                // SKU Tidak Cocok: channel_seller_sku marketplace <> SKU master.
                $this->whereMismatch($query, 'sku_status');
                break;

            case 'atribut':
                // Atribut Tidak Cocok: validasi skema kategori marketplace
                // (ChannelListingValidator) — termaterialisasi per produk × channel.
                $this->whereMismatch($query, 'attribute_status');
                break;

            case 'gagal_upload':
                $query->where(function ($w) {
                    // (a) ada log upload yang gagal
                    $w->whereHas('syncLogs', fn ($q) => $q
                        ->where('action', ProductSyncLog::ACTION_UPLOAD)
                        ->where('status', ProductSyncLog::STATUS_FAILED))
                    // (b) prediktif: multi-varian tanpa variant_options sama sekali
                    ->orWhere(fn ($p) => $p
                        ->has('variants', '>', 1)
                        ->whereDoesntHave('variants', fn ($v) => $v->has('options')));
                });
                break;

            default:
                break;
        }
    }

    private function whereMismatch($query, string $statusColumn): void
    {
        $channelCode = request('filter.channel');

        $query->whereHas('channelValidations', function ($q) use ($statusColumn, $channelCode) {
            $q->where($statusColumn, ProductChannelValidation::STATUS_MISMATCH);

            if ($channelCode) {
                $q->whereHas('channel', fn ($c) => $c->where('code', $channelCode));
            }
        });
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
