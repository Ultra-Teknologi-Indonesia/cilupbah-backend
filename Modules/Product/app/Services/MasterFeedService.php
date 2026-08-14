<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Repositories\MasterFeedRepository;

class MasterFeedService
{
    public function __construct(private MasterFeedRepository $repository) {}

    public function paginate(?string $status = null, ?string $updatedSince = null): LengthAwarePaginator
    {
        $paginator = $this->repository->paginate(
            $status ?? Product::STATUS_MASTER,
            $updatedSince,
        );

        $this->applyPageMergeGrouping($paginator);

        return \Modules\Product\Support\MasterFeedHydrator::hydrate($paginator);
    }

    public function paginateDownloaded(?string $updatedSince = null): LengthAwarePaginator
    {
        return $this->repository->paginateDownloaded($updatedSince);
    }

    public function find(string $id): Product
    {
        $product = $this->repository->find($id, Product::STATUS_MASTER);
        $hasRepCol = MasterFeedRepository::hasRepresentativeColumn();

        $mergeQuery = ProductMerge::query()->where('product_id', $product->id);
        if ($hasRepCol) {
            $mergeQuery->where('is_representative', true);
        }
        $merge = $mergeQuery->first(['product_id', 'master_name']);

        if (! $merge) {
            $this->markSolo($product);

            return $product;
        }

        $allMembers = ProductMerge::query()
            ->where('master_name', $merge->master_name)
            ->pluck('product_id')
            ->all();

        $siblingIds = array_values(array_filter($allMembers, fn ($pid) => $pid !== $product->id));
        $siblings = $this->repository->loadSiblings($siblingIds);

        $sibs = collect($siblingIds)
            ->map(fn ($sid) => $siblings->get($sid))
            ->filter();

        $product->setRelation('variants', $product->variants->concat($sibs->flatMap->variants)->values());
        $product->setRelation('media', $product->media->concat($sibs->flatMap->media)->values());
        $product->setRelation('channelMappings', $product->channelMappings->concat($sibs->flatMap->channelMappings)->values());
        $product->setRelation(
            'variationTypes',
            $product->variationTypes->concat($sibs->flatMap->variationTypes)->unique('id')->values(),
        );

        $product->name = $merge->master_name;
        $product->setAttribute('is_merged', true);
        $product->setAttribute('merge_master_name', $merge->master_name);
        $product->setAttribute('merge_member_ids', array_values($allMembers));

        return $product;
    }

    private function applyPageMergeGrouping(LengthAwarePaginator $paginator): void
    {
        $collection = $paginator->getCollection();
        if ($collection->isEmpty()) {
            return;
        }

        $productIds = $collection->pluck('id')->all();
        $hasRepCol = MasterFeedRepository::hasRepresentativeColumn();

        $repMergesQuery = ProductMerge::query()->whereIn('product_id', $productIds);
        if ($hasRepCol) {
            $repMergesQuery->where('is_representative', true);
        }
        $repMerges = $repMergesQuery->get(['product_id', 'master_name']);

        if ($repMerges->isEmpty()) {
            foreach ($collection as $product) {
                $this->markSolo($product);
            }

            return;
        }

        $masterNames = $repMerges->pluck('master_name')->unique()->all();
        $allMembers = ProductMerge::query()
            ->whereIn('master_name', $masterNames)
            ->get(['product_id', 'master_name']);

        $membersByMaster = [];
        foreach ($allMembers as $m) {
            $membersByMaster[$m->master_name][] = $m->product_id;
        }

        $repToMaster = [];
        $repToMembers = [];
        $siblingIds = [];

        foreach ($repMerges as $rm) {
            $repToMaster[$rm->product_id] = $rm->master_name;
            $memberList = $membersByMaster[$rm->master_name] ?? [$rm->product_id];
            $repToMembers[$rm->product_id] = $memberList;
            foreach ($memberList as $mid) {
                if ($mid !== $rm->product_id) {
                    $siblingIds[] = $mid;
                }
            }
        }

        $siblings = $this->repository->loadSiblings(array_values(array_unique($siblingIds)));

        foreach ($collection as $product) {
            if (! isset($repToMaster[$product->id])) {
                $this->markSolo($product);

                continue;
            }

            $master = $repToMaster[$product->id];
            $memberIds = $repToMembers[$product->id];

            $sibs = collect($memberIds)
                ->reject(fn ($id) => $id === $product->id)
                ->map(fn ($id) => $siblings->get($id))
                ->filter();

            $product->setRelation('variants', $product->variants->concat($sibs->flatMap->variants)->values());
            $product->setRelation('media', $product->media->concat($sibs->flatMap->media)->values());
            $product->setRelation('channelMappings', $product->channelMappings->concat($sibs->flatMap->channelMappings)->values());
            $product->setRelation(
                'variationTypes',
                $product->variationTypes->concat($sibs->flatMap->variationTypes)->unique('id')->values(),
            );

            $product->name = $master;
            $product->setAttribute('is_merged', true);
            $product->setAttribute('merge_master_name', $master);
            $product->setAttribute('merge_member_ids', array_values($memberIds));
        }
    }

    private function markSolo(Product $product): void
    {
        $product->setAttribute('is_merged', false);
        $product->setAttribute('merge_master_name', null);
        $product->setAttribute('merge_member_ids', [$product->id]);
    }
}
