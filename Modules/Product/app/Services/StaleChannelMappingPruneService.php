<?php

declare(strict_types=1);

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class StaleChannelMappingPruneService
{
    public function candidates(?string $channel = null, ?string $shopId = null, ?int $limit = null): Collection
    {
        return DB::table('product_channel_mappings as mapping')
            ->leftJoin('products as mapped_product', 'mapped_product.id', '=', 'mapping.product_id')
            ->leftJoin('product_variant_channel_mappings as child', 'child.product_channel_mapping_id', '=', 'mapping.id')
            ->leftJoin('product_variants as variant', 'variant.id', '=', 'child.variant_id')
            ->leftJoin('products as variant_product', 'variant_product.id', '=', 'variant.product_id')
            ->join('channel_shops as shop', 'shop.id', '=', 'mapping.channel_shop_id')
            ->join('channels as channel', 'channel.id', '=', 'shop.channel_id')
            ->where(function ($query): void {
                $query->whereNull('mapped_product.id')
                    ->orWhereNotNull('mapped_product.deleted_at')
                    ->orWhere(function ($childQuery): void {
                        $childQuery->whereNotNull('child.id')
                            ->where(function ($variantQuery): void {
                                $variantQuery->whereNull('variant.id')
                                    ->orWhereNotNull('variant.deleted_at')
                                    ->orWhereNull('variant_product.id')
                                    ->orWhereNotNull('variant_product.deleted_at');
                            });
                    });
            })
            ->when($channel !== null && $channel !== '', fn ($query) => $query->where('channel.code', $channel))
            ->when($shopId !== null && $shopId !== '', fn ($query) => $query->where('shop.shop_id', $shopId))
            ->selectRaw('mapping.id AS mapping_id, channel.code AS channel, shop.shop_id, shop.shop_name,
                mapping.external_product_id AS listing,
                COUNT(DISTINCT child.id) AS child_mappings,
                COUNT(DISTINCT child.id) FILTER (
                    WHERE variant.id IS NULL
                       OR variant.deleted_at IS NOT NULL
                       OR variant_product.id IS NULL
                       OR variant_product.deleted_at IS NOT NULL
                ) AS stale_children,
                BOOL_OR(mapped_product.id IS NULL OR mapped_product.deleted_at IS NOT NULL) AS stale_parent')
            ->groupBy('mapping.id', 'channel.code', 'shop.shop_id', 'shop.shop_name', 'mapping.external_product_id')
            ->orderBy('channel.code')
            ->orderBy('shop.shop_name')
            ->orderBy('mapping.external_product_id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }

    /**
     * @return array{mapping_id: string, deleted_children: int, deleted_parent: bool, reason: string}
     */
    public function prune(string $mappingId): array
    {
        return DB::transaction(function () use ($mappingId): array {
            $mapping = DB::table('product_channel_mappings')
                ->where('id', $mappingId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'product_id',
                    'channel_shop_id',
                    'external_product_id',
                ]);

            if (! $mapping) {
                throw new DomainException('Pembersihan dibatalkan: mapping sudah berubah atau tidak ditemukan.');
            }

            $mappedProduct = DB::table('products')
                ->where('id', $mapping->product_id)
                ->lockForUpdate()
                ->first(['id', 'deleted_at']);

            $children = DB::table('product_variant_channel_mappings')
                ->where('product_channel_mapping_id', $mapping->id)
                ->lockForUpdate()
                ->get(['id', 'variant_id']);

            $variants = DB::table('product_variants')
                ->whereIn('id', $children->pluck('variant_id')->all())
                ->lockForUpdate()
                ->get(['id', 'product_id', 'deleted_at'])
                ->keyBy('id');

            $variantProducts = DB::table('products')
                ->whereIn('id', $variants->pluck('product_id')->all())
                ->lockForUpdate()
                ->get(['id', 'deleted_at'])
                ->keyBy('id');

            $staleParent = $mappedProduct === null || $mappedProduct->deleted_at !== null;

            if ($staleParent) {
                $deletedChildren = DB::table('product_variant_channel_mappings')
                    ->where('product_channel_mapping_id', $mapping->id)
                    ->delete();

                DB::table('product_channel_mappings')->where('id', $mapping->id)->delete();

                $this->audit($mapping, $deletedChildren, true, 'LISTING_MASTER_DELETED');

                return [
                    'mapping_id' => (string) $mapping->id,
                    'deleted_children' => $deletedChildren,
                    'deleted_parent' => true,
                    'reason' => 'LISTING_MASTER_DELETED',
                ];
            }

            $staleChildIds = $children
                ->filter(function (object $child) use ($variants, $variantProducts): bool {
                    $variant = $variants->get($child->variant_id);
                    $variantProduct = $variant === null ? null : $variantProducts->get($variant->product_id);

                    return $variant === null
                        || $variant->deleted_at !== null
                        || $variantProduct === null
                        || $variantProduct->deleted_at !== null;
                })
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if ($staleChildIds === []) {
                throw new DomainException(
                    'Pembersihan dibatalkan: mapping tanpa varian atau tanpa varian stale wajib ditinjau manual.'
                );
            }

            $deletedChildren = $staleChildIds === []
                ? 0
                : DB::table('product_variant_channel_mappings')->whereIn('id', $staleChildIds)->delete();

            $hasChildren = DB::table('product_variant_channel_mappings')
                ->where('product_channel_mapping_id', $mapping->id)
                ->exists();

            if (! $hasChildren) {
                DB::table('product_channel_mappings')->where('id', $mapping->id)->delete();
            }

            $this->audit($mapping, $deletedChildren, ! $hasChildren, 'VARIANT_DELETED');

            return [
                'mapping_id' => (string) $mapping->id,
                'deleted_children' => $deletedChildren,
                'deleted_parent' => ! $hasChildren,
                'reason' => 'VARIANT_DELETED',
            ];
        }, 3);
    }

    private function audit(object $mapping, int $deletedChildren, bool $deletedParent, string $reason): void
    {
        DB::table('channel_mapping_prune_audits')->insert([
            'id' => (string) Uuid::uuid7(),
            'mapping_id' => (string) $mapping->id,
            'channel_shop_id' => (string) $mapping->channel_shop_id,
            'external_product_id' => $mapping->external_product_id,
            'reason' => $reason,
            'deleted_children' => $deletedChildren,
            'deleted_parent' => $deletedParent,
            'pruned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
