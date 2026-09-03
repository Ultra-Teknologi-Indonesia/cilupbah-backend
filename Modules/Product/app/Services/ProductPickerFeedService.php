<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\MasterFeedRepository;
use Modules\Product\Support\ProductPickerHydrator;
use Modules\Product\Support\TechnicalSku;

class ProductPickerFeedService
{
    public function __construct(private MasterFeedRepository $repository) {}

    public function paginate(
        ?string $search = null,
        int $perPage = 20,
        int $page = 1,
        bool $excludeBundles = false,
    ): LengthAwarePaginator
    {
        $status = Product::STATUS_MASTER;
        $hasRepCol = MasterFeedRepository::hasRepresentativeColumn();

        $search = trim($search ?? '');

        if ($search === '') {
            $paginator = $this->repository->paginate($status, null, $excludeBundles);
            $this->applyPageMergeGrouping($paginator);

            return ProductPickerHydrator::hydrate($paginator);
        }

        $matchingVariantQuery = TechnicalSku::exclude(ProductVariant::query(), 'product_variants.sku')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.status', $status)
            ->whereNull('product_variants.deleted_at')
            ->where(function ($q) use ($search) {
                $q->where('product_variants.sku', 'ILIKE', "%{$search}%")
                    ->orWhere('product_variants.barcode', 'ILIKE', "%{$search}%")
                    ->orWhere('products.name', 'ILIKE', "%{$search}%")
                    ->orWhereExists(function ($sub) use ($search) {
                        $sub->select(DB::raw(1))
                            ->from('variant_options')
                            ->whereColumn('variant_options.variant_id', 'product_variants.id')
                            ->where('variant_options.value', 'ILIKE', "%{$search}%");
                    });
            });

        $matchingVariants = $matchingVariantQuery->get(['product_variants.id', 'product_variants.product_id']);
        $matchingVariantIds = $matchingVariants->pluck('id')->all();
        $matchedProductIds = $matchingVariants->pluck('product_id')->unique()->all();

        $bundleMatchedProductIds = [];
        if (! $excludeBundles) {
            $bundleMatchedQuery = TechnicalSku::exclude(DB::table('product_bundle_items')
                ->join('product_variants', 'product_variants.id', '=', 'product_bundle_items.component_variant_id')
                ->join('products', 'products.id', '=', 'product_bundle_items.bundle_product_id')
                ->where('products.status', $status)
                ->whereNull('product_variants.deleted_at')
                ->where(function ($q) use ($search) {
                    $q->where('product_variants.sku', 'ILIKE', "%{$search}%")
                        ->orWhere('products.name', 'ILIKE', "%{$search}%");
                }), 'product_variants.sku');

            $bundleMatchedProductIds = $bundleMatchedQuery
                ->pluck('product_bundle_items.bundle_product_id')
                ->all();
        }

        $allMatchedProductIds = array_values(array_unique(array_merge($matchedProductIds, $bundleMatchedProductIds)));

        if (empty($allMatchedProductIds)) {
            return new ConcreteLengthAwarePaginator(
                [],
                0,
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        $repMergesQuery = ProductMerge::query()->whereIn('product_id', $allMatchedProductIds);
        if ($hasRepCol) {
            $repMergesQuery->where('is_representative', true);
        }
        $repMerges = $repMergesQuery->get(['product_id', 'master_name']);

        $representativeProductIds = [];
        if ($repMerges->isNotEmpty()) {
            $masterNames = $repMerges->pluck('master_name')->unique()->all();
            $allRepProductIds = ProductMerge::query()
                ->whereIn('master_name', $masterNames)
                ->when($hasRepCol, fn ($q) => $q->where('is_representative', true))
                ->pluck('product_id')
                ->all();
            $representativeProductIds = $allRepProductIds;
        }

        $finalProductIds = array_values(array_unique(array_merge($allMatchedProductIds, $representativeProductIds)));

        $query = Product::query()
            ->where('status', $status)
            ->whereIn('id', $finalProductIds)
            ->when($excludeBundles, fn ($q) => $q->where('is_bundle', false));

        if ($hasRepCol) {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_merges')
                    ->whereColumn('product_merges.product_id', 'products.id')
                    ->where('product_merges.is_representative', false);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page)->appends(request()->query());

        $this->applyPageMergeGrouping($paginator);

        return ProductPickerHydrator::hydrate($paginator, $matchingVariantIds, $search);
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
