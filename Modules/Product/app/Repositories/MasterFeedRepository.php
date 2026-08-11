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

    /**
     * @param  array  $context  the merge grouping context from mergeContext()
     */
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
                ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
                ->with(self::RELATIONS)
        )
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    /**
     * Flat feed used when nothing is merged — byte-identical to the original
     * (non-merge-aware) behaviour.
     */
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

    /**
     * Representative rows only, optionally restricted to the masters whose
     * members matched the search/filter criteria. Search/filter are already
     * resolved into $restrictRepIds so they are NOT re-applied here (that would
     * re-filter on the representative alone).
     *
     * @param  string[]  $nonRepIds
     * @param  string[]|null  $restrictRepIds  null = no criteria (show all masters)
     */
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

    /**
     * Scan EVERY product (representatives and hidden members alike) for the
     * current search/filter criteria, then map each match back to its
     * representative. A master surfaces when ANY of its members matches.
     *
     * @return string[] representative ids to keep
     */
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

    /**
     * Build the display-time merge grouping. For every set of products that
     * share a master_name, one is chosen as the representative row and the
     * others are hidden from the flat feed (their data is folded into the
     * representative). When nothing is merged this returns an inert context so
     * the feed stays byte-identical to the non-merge-aware behaviour.
     *
     * @return array{active: bool, repToMaster: array<string,string>, repToMembers: array<string,array<int,string>>, memberToRep: array<string,string>, nonRepIds: array<int,string>}
     */
    public function mergeContext(): array
    {
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
    }

    /**
     * @param  string[]  $ids
     * @return Collection<string, Product>
     */
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
