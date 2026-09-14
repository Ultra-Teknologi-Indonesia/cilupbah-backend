<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MasterProductMerger
{
    public function __construct(private readonly ChannelMappingIntegrityService $channelMappings) {}

    public function moveVariants(string $targetProductId, array $variantIds): int
    {
        $variantIds = array_values(array_filter(array_unique(array_map('strval', $variantIds))));

        if (! $variantIds) {
            return 0;
        }

        return DB::transaction(function () use ($targetProductId, $variantIds) {
            $moving = DB::table('product_variants')
                ->whereIn('id', $variantIds)
                ->where('product_id', '!=', $targetProductId)
                ->pluck('id')
                ->all();

            if (! $moving) {
                return 0;
            }

            $parentMappingsToReassign = $this->channelMappings
                ->parentMappingsForVariantMove($targetProductId, $moving);

            $this->ensureVariationTypes($targetProductId, $moving);

            DB::table('product_media')
                ->whereIn('variant_id', $moving)
                ->update(['product_id' => $targetProductId, 'updated_at' => now()]);

            DB::table('product_variants')
                ->whereIn('id', $moving)
                ->update(['product_id' => $targetProductId, 'updated_at' => now()]);

            if ($parentMappingsToReassign->isNotEmpty()) {
                DB::table('product_channel_mappings')
                    ->whereIn('id', $parentMappingsToReassign->all())
                    ->update(['product_id' => $targetProductId, 'updated_at' => now()]);

                Log::info('Listing channel ikut dipindahkan saat varian dikonsolidasikan', [
                    'master_tujuan' => $targetProductId,
                    'varian_pindah' => count($moving),
                    'listing_dipindahkan' => $parentMappingsToReassign->count(),
                ]);
            }

            return count($moving);
        });
    }

    public function resolveWinner(array $productIds): ?string
    {
        $productIds = array_values(array_filter(array_unique(array_map('strval', $productIds))));

        if (! $productIds) {
            return null;
        }

        if (count($productIds) === 1) {
            return $productIds[0];
        }

        return DB::table('products')
            ->whereIn('id', $productIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');
    }

    private function ensureVariationTypes(string $targetProductId, array $variantIds): void
    {
        $attributeIds = DB::table('variant_options')
            ->whereIn('variant_id', $variantIds)
            ->distinct()
            ->pluck('attribute_id')
            ->all();

        if (! $attributeIds) {
            return;
        }

        $existing = DB::table('product_variation_types')
            ->where('product_id', $targetProductId)
            ->pluck('attribute_id')
            ->all();

        $sort = (int) DB::table('product_variation_types')
            ->where('product_id', $targetProductId)
            ->max('sort_order');

        foreach ($attributeIds as $attributeId) {
            if (in_array($attributeId, $existing, false)) {
                continue;
            }

            $sort++;
            DB::table('product_variation_types')->insert([
                'product_id' => $targetProductId,
                'attribute_id' => $attributeId,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $existing[] = $attributeId;
        }
    }
}
