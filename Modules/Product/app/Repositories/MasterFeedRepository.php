<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    public function paginate(string $status, ?string $updatedSince, array $context): LengthAwarePaginator
    {
        if (! ($context['active'] ?? false)) {
            return $this->plainFeedQuery($status, $updatedSince);
        }

        return $this->mergeAwareQuery($status, $updatedSince, $context);
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
            ->paginate(request('per_page', 20), ProductFeedColumns::list())
            ->appends(request()->query());
    }

    private function plainFeedQuery(string $status, ?string $updatedSince): LengthAwarePaginator
    {
        return ProductFeedQuery::configure(
            QueryBuilder::for(Product::class)
                ->where('status', $status)
                ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
                ->with($this->relations())
        )
            ->paginate(request('per_page', 20), ProductFeedColumns::list())
            ->appends(request()->query());
    }

    private function mergeAwareQuery(string $status, ?string $updatedSince, array $context): LengthAwarePaginator
    {
        $nonRepIds = $context['nonRepIds'];

        $query = Product::query()
            ->where('status', $status)
            ->when($updatedSince, fn ($q) => $q->where('updated_at', '>=', $updatedSince))
            ->when(! empty($nonRepIds), fn ($q) => $q->whereNotIn('id', $nonRepIds));

        if (ProductFeedQuery::hasCriteria() && ProductFeedQuery::criteriaAreEffective(Product::query())) {
            $memberRepIds = $this->representativesOfMatchingMembers($status, $updatedSince, $context);

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
            ->paginate(request('per_page', 20), ProductFeedColumns::list())
            ->appends(request()->query());
    }

    private function representativesOfMatchingMembers(string $status, ?string $updatedSince, array $context): array
    {
        $nonRepIds = $context['nonRepIds'];

        if (empty($nonRepIds)) {
            return [];
        }

        $memberToRep = $context['memberToRep'];

        $matched = Product::query()
            ->where('status', $status)
            ->when($updatedSince, fn ($q) => $q->where('updated_at', '>=', $updatedSince))
            ->whereIn('id', $nonRepIds)
            ->where(fn (Builder $q) => ProductFeedQuery::applyCriteriaTo($q))
            ->pluck('id');

        $reps = [];
        foreach ($matched as $id) {
            $reps[$memberToRep[$id] ?? $id] = true;
        }

        return array_keys($reps);
    }

    public function mergeContext(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('product_merges_context', 300, function () {
            $merges = ProductMerge::query()->get(['product_id', 'master_name']);
            if ($merges->isEmpty()) {
                return [
                    'active' => false,
                    'repToMaster' => [],
                    'repToMembers' => [],
                    'memberToRep' => [],
                    'nonRepIds' => [],
                ];
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
            $memberToRep = [];
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
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }
}
