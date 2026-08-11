<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\MasterFeedRepository;

class MasterFeedService
{
    public function __construct(private MasterFeedRepository $repository) {}

    public function paginate(?string $status = null, ?string $updatedSince = null): LengthAwarePaginator
    {
        $context = $this->repository->mergeContext();

        $paginator = $this->repository->paginate(
            $status ?? Product::STATUS_MASTER,
            $updatedSince,
            $context,
        );

        if ($context['active']) {
            $this->applyMergeGrouping($paginator, $context);
        } else {
            foreach ($paginator->getCollection() as $product) {
                $this->markSolo($product);
            }
        }

        return $paginator;
    }

    public function paginateDownloaded(?string $updatedSince = null): LengthAwarePaginator
    {
        return $this->repository->paginateDownloaded($updatedSince);
    }

    public function find(string $id): Product
    {
        return $this->repository->find($id, Product::STATUS_MASTER);
    }

    /**
     * Fold sibling products (variants, media, channels) into the representative
     * row so merged products render as a single master with combined data.
     */
    private function applyMergeGrouping(LengthAwarePaginator $paginator, array $context): void
    {
        $repToMaster = $context['repToMaster'];
        $repToMembers = $context['repToMembers'];

        $siblingIds = [];
        foreach ($paginator->getCollection() as $product) {
            if (! isset($repToMaster[$product->id])) {
                continue;
            }
            foreach ($repToMembers[$product->id] as $memberId) {
                if ($memberId !== $product->id) {
                    $siblingIds[] = $memberId;
                }
            }
        }

        $siblings = $this->repository->loadSiblings(array_values(array_unique($siblingIds)));

        foreach ($paginator->getCollection() as $product) {
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
