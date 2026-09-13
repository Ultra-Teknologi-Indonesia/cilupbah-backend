<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the integrity rules for marketplace-variant mappings.
 */
final class ChannelMappingIntegrityService
{
    public function assertVariantCanBeLinked(string $channelMappingId, string $variantId): void
    {
        $valid = DB::table('product_variants as variant')
            ->join('product_channel_mappings as mapping', function ($join): void {
                $join->on('mapping.product_id', '=', 'variant.product_id');
            })
            ->join('products as product', 'product.id', '=', 'mapping.product_id')
            ->where('mapping.id', $channelMappingId)
            ->where('variant.id', $variantId)
            ->where('product.is_active', true)
            ->where('variant.is_active', true)
            ->whereNull('product.deleted_at')
            ->whereNull('variant.deleted_at')
            ->exists();

        if (! $valid) {
            throw new DomainException(
                'Mapping channel ditolak: varian harus aktif, belum dihapus, dan berasal dari produk master yang sama dengan listing.'
            );
        }
    }

    /**
     * Cleans channel links when a product was soft-deleted outside the normal
     * ProductService deletion workflow.
     */
    public function removeForDeletedProduct(string $productId): void
    {
        $mappingIds = DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->pluck('id');

        if ($mappingIds->isEmpty()) {
            return;
        }

        DB::table('product_variant_channel_mappings')
            ->whereIn('product_channel_mapping_id', $mappingIds)
            ->delete();

        DB::table('product_channel_mappings')
            ->whereIn('id', $mappingIds)
            ->delete();
    }

    /**
     * Keeps a live product listing but removes its deleted variant from it.
     */
    public function removeForDeletedVariant(string $variantId): void
    {
        DB::table('product_variant_channel_mappings')
            ->where('variant_id', $variantId)
            ->delete();
    }
}
