<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class MasterFeedRepository
{
    private const RELATIONS = [
        'category',
        'variationTypes.attribute',
        'variants.options.attribute',
        'variants.channelMappings.channelMapping.channelShop.channel',
        'media',
        'channelMappings.channelShop.channel',
    ];

    public function paginate(string $status, ?string $updatedSince = null, array $excludeIds = []): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->where('status', $status)
            ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
            ->when(! empty($excludeIds), fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->with(self::RELATIONS)
            ->allowedSearch('name', 'sku')
            ->allowedFilters(
                AllowedFilter::callback('category_id', function ($query, $value) {
                    $query->whereIn('category_id', $this->categoryWithDescendants($value));
                }),

                AllowedFilter::callback('type', function ($query, $value) {
                    match ($value) {
                        'bundle' => $query->where('is_bundle', true),
                        'konsinyasi' => $query->where('is_consignment', true),
                        'pre_order' => $query->where('order_type', 'PREORDER'),
                        'satuan' => $query->where('is_bundle', false)
                            ->where('is_consignment', false)
                            ->where(fn ($q) => $q->where('order_type', '<>', 'PREORDER')->orWhereNull('order_type')),
                        default => null,
                    };
                }),

                AllowedFilter::callback('min_price', fn ($query, $value) => $query->whereHas('variants', fn ($q) => $q->where('sell_price', '>=', $value))),
                AllowedFilter::callback('max_price', fn ($query, $value) => $query->whereHas('variants', fn ($q) => $q->where('sell_price', '<=', $value))),

                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelMappings.channelShop.channel', fn ($q) => $q->where('code', $value))),
            )
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->defaultSort('-updated_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
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

    public function paginateDownloaded(?string $updatedSince = null): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->where('is_from_channel', true)
            ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
            ->with(self::RELATIONS)
            ->allowedSearch('name', 'sku')
            ->allowedFilters(
                AllowedFilter::callback('category_id', function ($query, $value) {
                    $query->whereIn('category_id', $this->categoryWithDescendants($value));
                }),

                AllowedFilter::callback('type', function ($query, $value) {
                    match ($value) {
                        'bundle' => $query->where('is_bundle', true),
                        'konsinyasi' => $query->where('is_consignment', true),
                        'pre_order' => $query->where('order_type', 'PREORDER'),
                        'satuan' => $query->where('is_bundle', false)
                            ->where('is_consignment', false)
                            ->where(fn ($q) => $q->where('order_type', '<>', 'PREORDER')->orWhereNull('order_type')),
                        default => null,
                    };
                }),

                AllowedFilter::callback('min_price', fn ($query, $value) => $query->whereHas('variants', fn ($q) => $q->where('sell_price', '>=', $value))),
                AllowedFilter::callback('max_price', fn ($query, $value) => $query->whereHas('variants', fn ($q) => $q->where('sell_price', '<=', $value))),

                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelMappings.channelShop.channel', fn ($q) => $q->where('code', $value))),
            )
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->defaultSort('-updated_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    /**
     * Build the display-time merge grouping. For every set of products that
     * share a master_name, one is chosen as the representative row and the
     * others are hidden from the flat feed (their data is folded into the
     * representative). When nothing is merged this returns an inert context so
     * the feed stays byte-identical to the non-merge-aware behaviour.
     *
     * @return array{active: bool, repToMaster: array<string,string>, repToMembers: array<string,array<int,string>>, nonRepIds: array<int,string>}
     */
    public function mergeContext(): array
    {
        $merges = \Modules\Product\Models\ProductMerge::query()->get(['product_id', 'master_name']);
        if ($merges->isEmpty()) {
            return ['active' => false, 'repToMaster' => [], 'repToMembers' => [], 'nonRepIds' => []];
        }

        $byMaster = [];
        foreach ($merges as $m) {
            $byMaster[$m->master_name][$m->product_id] = true;
        }

        $names = Product::query()
            ->whereIn('id', $merges->pluck('product_id')->all())
            ->pluck('name', 'id')
            ->all();

        $repToMaster = [];
        $repToMembers = [];
        $nonRepIds = [];

        foreach ($byMaster as $master => $idSet) {
            $ids = array_keys($idSet);
            sort($ids);

            $rep = null;
            foreach ($ids as $id) {
                if (($names[$id] ?? null) === $master) {
                    $rep = $id;
                    break;
                }
            }
            $rep ??= $ids[0];

            $repToMaster[$rep] = $master;
            $repToMembers[$rep] = $ids;
            foreach ($ids as $id) {
                if ($id !== $rep) {
                    $nonRepIds[] = $id;
                }
            }
        }

        return [
            'active' => true,
            'repToMaster' => $repToMaster,
            'repToMembers' => $repToMembers,
            'nonRepIds' => $nonRepIds,
        ];
    }

    /**
     * @param  string[]  $ids
     * @return \Illuminate\Support\Collection<string, Product>
     */
    public function loadSiblings(array $ids): \Illuminate\Support\Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->with(self::RELATIONS)
            ->get()
            ->keyBy('id');
    }

    public function find(string $id, string $status): Product
    {
        return Product::query()
            ->where('status', $status)
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    public function findForListing(string $id): ?Product
    {
        return Product::query()->with(self::RELATIONS)->find($id);
    }

    public function paginateByMasterName(string $masterName): LengthAwarePaginator
    {
        $productIds = \Modules\Product\Models\ProductMerge::where('master_name', $masterName)->pluck('product_id');

        return QueryBuilder::for(Product::class)
            ->whereIn('id', $productIds)
            ->with(self::RELATIONS)
            ->allowedSearch('name', 'sku')
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->defaultSort('-updated_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }
}
