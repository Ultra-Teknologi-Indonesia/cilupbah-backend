<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Support\ProductFeedQuery;
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

    public function paginate(string $status, ?string $updatedSince, array $context): LengthAwarePaginator
    {
        if (! ($context['active'] ?? false)) {
            return $this->plainFeedQuery($status, $updatedSince);
        }

        $restrictRepIds = ProductFeedQuery::hasCriteria()
            ? $this->matchingRepresentativeIds($status, $updatedSince, $context['memberToRep'])
            : null;

        return $this->mergeAwareQuery($status, $updatedSince, $context['nonRepIds'], $restrictRepIds);
    }

    public function paginateDownloaded(?string $updatedSince = null): LengthAwarePaginator
    {
        return ProductFeedQuery::configure(
            QueryBuilder::for(Product::class)
                ->where('is_from_channel', true)
                ->where('status', '<>', Product::STATUS_ARCHIVED)
                ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
                ->with(self::RELATIONS)
        )
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    private function plainFeedQuery(string $status, ?string $updatedSince): LengthAwarePaginator
    {
        return ProductFeedQuery::configure(
            QueryBuilder::for(Product::class)
                ->where('status', $status)
                ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
                ->with(self::RELATIONS)
        )
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    private function mergeAwareQuery(
        string $status,
        ?string $updatedSince,
        array $nonRepIds,
        ?array $restrictRepIds,
    ): LengthAwarePaginator {
        $query = Product::query()
            ->where('status', $status)
            ->when($updatedSince, fn ($q) => $q->where('updated_at', '>=', $updatedSince))
            ->when(! empty($nonRepIds), fn ($q) => $q->whereNotIn('id', $nonRepIds))
            ->when($restrictRepIds !== null, fn ($q) => $q->whereIn('id', $restrictRepIds))
            ->with(self::RELATIONS);

        ProductFeedQuery::applySort($query);

        return $query
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    private function matchingRepresentativeIds(string $status, ?string $updatedSince, array $memberToRep): array
    {
        $matchedIds = ProductFeedQuery::applyCriteria(
            QueryBuilder::for(Product::class)
                ->where('status', $status)
                ->when($updatedSince, fn ($q) => $q->where('updated_at', '>=', $updatedSince))
        )->pluck('id')->all();

        $repIds = [];
        foreach ($matchedIds as $id) {
            $repIds[$memberToRep[$id] ?? $id] = true;
        }

        return array_keys($repIds);
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
        $productIds = ProductMerge::where('master_name', $masterName)->pluck('product_id');

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
