<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
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

        $paginator->getCollection()->each(function (Product $product) use ($activeShopCount) {
            $product->not_uploaded_count = $activeShopCount - (int) $product->synced_shop_count;
        });

        if (in_array($lens, ['direview', 'ditolak'])) {
            $targetStatus = $lens === 'direview'
                ? ProductChannelMapping::STATUS_IN_REVIEW
                : ProductChannelMapping::STATUS_REJECTED;

            $paginator->getCollection()->load([
                'channelMappings' => fn ($q) => $q->where('sync_status', $targetStatus)
                    ->with('channelShop.channel'),
            ]);
        }

        return $paginator;
    }

    private function applyLens($query, string $lens, int $activeShopCount): void
    {
        switch ($lens) {
            case 'belum_upload':

                $query->whereHas(
                    'channelMappings',
                    fn ($q) => $q->where('sync_status', '<>', ProductChannelMapping::STATUS_DEACTIVATED),
                    '<',
                    $activeShopCount
                );
                break;

            case 'harga':

                $this->whereMismatch($query, 'price_status');
                break;

            case 'sku':

                $this->whereMismatch($query, 'sku_status');
                break;

            case 'atribut':

                $this->whereMismatch($query, 'attribute_status');
                break;

            case 'gagal_upload':
                $query->where(function ($w) {

                    $w->whereHas('syncLogs', fn ($q) => $q
                        ->where('action', ProductSyncLog::ACTION_UPLOAD)
                        ->where('status', ProductSyncLog::STATUS_FAILED))

                    ->orWhere(fn ($p) => $p
                        ->has('variants', '>', 1)
                        ->whereDoesntHave('variants', fn ($v) => $v->has('options')));
                });
                break;

            case 'persyaratan':
                $tiktokChannelId = Channel::where('code', 'tiktok')->value('id');
                if ($tiktokChannelId) {
                    $query->whereIn('category_id', function ($sub) use ($tiktokChannelId) {
                        $sub->select('ccm.category_id')
                            ->from('category_channel_mappings as ccm')
                            ->join('channel_categories as cc', 'cc.id', '=', 'ccm.channel_category_id')
                            ->where('cc.channel_id', $tiktokChannelId)
                            ->whereNotNull('cc.rules');
                    });
                }
                break;

            case 'direview':
                $query->whereHas('channelMappings', fn ($q) => $q->where('sync_status', ProductChannelMapping::STATUS_IN_REVIEW));
                break;

            case 'ditolak':
                $query->whereHas('channelMappings', fn ($q) => $q->where('sync_status', ProductChannelMapping::STATUS_REJECTED));
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
