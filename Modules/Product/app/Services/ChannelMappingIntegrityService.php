<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChannelMappingIntegrityService
{
    public function parentMappingsForVariantMove(string $targetProductId, array $movingVariantIds): Collection
    {
        $movingVariantIds = array_values(array_filter(array_unique(array_map('strval', $movingVariantIds))));

        if ($movingVariantIds === []) {
            return collect();
        }

        $affectedMappingIds = DB::table('product_variant_channel_mappings')
            ->whereIn('variant_id', $movingVariantIds)
            ->lockForUpdate()
            ->pluck('product_channel_mapping_id')
            ->unique()
            ->values();

        if ($affectedMappingIds->isEmpty()) {
            return collect();
        }

        $mappings = DB::table('product_channel_mappings')
            ->whereIn('id', $affectedMappingIds)
            ->lockForUpdate()
            ->get(['id', 'product_id', 'channel_shop_id', 'external_product_id'])
            ->keyBy('id');

        if ($mappings->count() !== $affectedMappingIds->count()) {
            throw new DomainException('Penggabungan master dibatalkan: terdapat listing channel yang berubah saat proses berjalan.');
        }

        $children = DB::table('product_variant_channel_mappings as mapping')
            ->join('product_variants as variant', 'variant.id', '=', 'mapping.variant_id')
            ->join('products as product', 'product.id', '=', 'variant.product_id')
            ->whereIn('mapping.product_channel_mapping_id', $affectedMappingIds)
            ->lockForUpdate()
            ->get([
                'mapping.product_channel_mapping_id',
                'mapping.variant_id',
                'variant.product_id as variant_product_id',
                'variant.is_active as variant_active',
                'variant.deleted_at as variant_deleted_at',
                'product.is_active as product_active',
                'product.deleted_at as product_deleted_at',
            ])
            ->groupBy('product_channel_mapping_id');

        $moving = array_fill_keys($movingVariantIds, true);
        $reassign = collect();

        foreach ($mappings as $mapping) {
            $listingChildren = $children->get($mapping->id, collect());

            if ($listingChildren->isEmpty()) {
                throw new DomainException('Penggabungan master dibatalkan: listing channel tanpa varian tidak dapat diverifikasi.');
            }

            $projectedOwners = $listingChildren->map(function (object $child) use ($moving, $targetProductId): string {
                if (! $child->variant_active || $child->variant_deleted_at !== null || ! $child->product_active || $child->product_deleted_at !== null) {
                    throw new DomainException('Penggabungan master dibatalkan: listing channel memiliki varian atau master yang tidak aktif.');
                }

                return isset($moving[(string) $child->variant_id])
                    ? $targetProductId
                    : (string) $child->variant_product_id;
            })->unique()->values();

            if ($projectedOwners->count() !== 1 || $projectedOwners->first() !== $targetProductId) {
                throw new DomainException(
                    'Penggabungan master dibatalkan: perpindahan varian akan mencampur lebih dari satu master pada satu listing channel.'
                );
            }

            if ((string) $mapping->product_id === $targetProductId) {
                continue;
            }

            $duplicate = DB::table('product_channel_mappings')
                ->where('channel_shop_id', $mapping->channel_shop_id)
                ->where('product_id', $targetProductId)
                ->where('id', '!=', $mapping->id)
                ->where(function ($query) use ($mapping): void {
                    if ($mapping->external_product_id === null) {
                        $query->whereNull('external_product_id');

                        return;
                    }

                    $query->where('external_product_id', $mapping->external_product_id);
                })
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw new DomainException(
                    'Penggabungan master dibatalkan: listing channel tujuan sudah memiliki tautan master lain dan perlu ditinjau terlebih dahulu.'
                );
            }

            $reassign->push((string) $mapping->id);
        }

        return $reassign;
    }

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

    public function removeForDeletedVariant(string $variantId): void
    {
        DB::table('product_variant_channel_mappings')
            ->where('variant_id', $variantId)
            ->delete();
    }
}
