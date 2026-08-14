<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Support\ProductFeedColumns;
use Modules\Product\Support\ProductFeedQuery;
use Modules\Product\Support\ProductFeedRelations;
use Spatie\QueryBuilder\QueryBuilder;

class MasterFeedRepository
{
    private function relations(): array
    {
        return ProductFeedRelations::base();
    }

    public function paginate(?string $status = null, ?string $updatedSince = null): LengthAwarePaginator
    {
        $status = $status ?? Product::STATUS_MASTER;

        $query = Product::query()
            ->where('status', $status)
            ->when($updatedSince, fn ($q) => $q->where('updated_at', '>=', $updatedSince))
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_merges')
                    ->whereColumn('product_merges.product_id', 'products.id')
                    ->where('product_merges.is_representative', false);
            });

        if (ProductFeedQuery::hasCriteria() && ProductFeedQuery::criteriaAreEffective(Product::query())) {
            $memberRepIds = $this->representativesOfMatchingMembers($status, $updatedSince);

            $query->where(function (Builder $outer) use ($memberRepIds) {
                $outer->where(fn (Builder $inner) => ProductFeedQuery::applyCriteriaTo($inner));

                if (! empty($memberRepIds)) {
                    $outer->orWhereIn('id', $memberRepIds);
                }
            });
        }

        ProductFeedQuery::applySort($query);

        return $query
            ->with($this->relations())
            ->paginate(request('per_page', 10), ProductFeedColumns::list())
            ->appends(request()->query());
    }

    public function paginateDownloaded(?string $updatedSince = null): LengthAwarePaginator
    {
        return ProductFeedQuery::configure(
            QueryBuilder::for(Product::class)
                ->where('is_from_channel', true)
                ->where('status', '<>', Product::STATUS_ARCHIVED)
                ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
                ->with($this->relations())
        )
            ->paginate(request('per_page', 10), ProductFeedColumns::list())
            ->appends(request()->query());
    }

    private function representativesOfMatchingMembers(string $status, ?string $updatedSince): array
    {
        $matchingNonRepProductIds = Product::query()
            ->where('status', $status)
            ->when($updatedSince, fn ($q) => $q->where('updated_at', '>=', $updatedSince))
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_merges')
                    ->whereColumn('product_merges.product_id', 'products.id')
                    ->where('product_merges.is_representative', false);
            })
            ->where(fn (Builder $q) => ProductFeedQuery::applyCriteriaTo($q))
            ->pluck('id')
            ->all();

        if (empty($matchingNonRepProductIds)) {
            return [];
        }

        $masterNames = ProductMerge::query()
            ->whereIn('product_id', $matchingNonRepProductIds)
            ->pluck('master_name')
            ->unique()
            ->all();

        if (empty($masterNames)) {
            return [];
        }

        return ProductMerge::query()
            ->whereIn('master_name', $masterNames)
            ->where('is_representative', true)
            ->pluck('product_id')
            ->all();
    }

    public function mergeContext(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('product_merges_context', 300, function () {
            $merges = ProductMerge::query()->get(['product_id', 'master_name', 'is_representative']);
            if ($merges->isEmpty()) {
                return [
                    'active' => false,
                    'repToMaster' => [],
                    'repToMembers' => [],
                    'memberToRep' => [],
                    'nonRepIds' => [],
                ];
            }

            $repToMaster = [];
            $repToMembers = [];
            $memberToRep = [];
            $nonRepIds = [];

            $byMaster = [];
            foreach ($merges as $m) {
                $byMaster[$m->master_name][] = $m;
            }

            foreach ($byMaster as $master => $items) {
                $rep = null;
                $ids = [];
                foreach ($items as $item) {
                    $ids[] = $item->product_id;
                    if ($item->is_representative) {
                        $rep = $item->product_id;
                    }
                }
                $rep ??= $ids[0];

                $repToMaster[$rep] = $master;
                $repToMembers[$rep] = $ids;
                foreach ($ids as $id) {
                    $memberToRep[$id] = $rep;
                    if ($id !== $rep) {
                        $nonRepIds[] = $id;
                    }
                }
            }

            return [
                'active' => true,
                'repToMaster' => $repToMaster,
                'repToMembers' => $repToMembers,
                'memberToRep' => $memberToRep,
                'nonRepIds' => $nonRepIds,
            ];
        });
    }

    public function loadSiblings(array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->with($this->relations())
            ->get(ProductFeedColumns::list())
            ->keyBy('id');
    }

    public function find(string $id, string $status): Product
    {
        return Product::query()
            ->where('status', $status)
            ->with($this->relations())
            ->findOrFail($id);
    }

    public function findForListing(string $id): ?Product
    {
        return Product::query()->with($this->relations())->find($id);
    }

    public function paginateByMasterName(string $masterName): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->whereIn('id', ProductMerge::where('master_name', $masterName)->select('product_id'))
            ->with($this->relations())
            ->allowedSearch('name', 'sku')
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->defaultSort('-updated_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }
}
