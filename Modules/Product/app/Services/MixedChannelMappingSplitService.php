<?php

declare(strict_types=1);

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class MixedChannelMappingSplitService
{
    public function candidates(?string $channel = null, ?string $shopId = null, ?int $limit = null): Collection
    {
        $mappingIds = DB::table('product_channel_mappings as mapping')
            ->join('products as mapped_product', 'mapped_product.id', '=', 'mapping.product_id')
            ->join('product_variant_channel_mappings as child', 'child.product_channel_mapping_id', '=', 'mapping.id')
            ->join('product_variants as variant', 'variant.id', '=', 'child.variant_id')
            ->join('products as variant_product', 'variant_product.id', '=', 'variant.product_id')
            ->join('channel_shops as shop', 'shop.id', '=', 'mapping.channel_shop_id')
            ->join('channels as channel', 'channel.id', '=', 'shop.channel_id')
            ->whereNull('mapped_product.deleted_at')
            ->where('mapped_product.is_active', true)
            ->where('mapping.sync_status', '<>', 'deactivated')
            ->whereNull('variant.deleted_at')
            ->where('variant.is_active', true)
            ->whereNull('variant_product.deleted_at')
            ->where('variant_product.is_active', true)
            ->whereColumn('variant.product_id', '<>', 'mapping.product_id')
            ->when($channel !== null && $channel !== '', fn ($query) => $query->where('channel.code', $channel))
            ->when($shopId !== null && $shopId !== '', fn ($query) => $query->where('shop.shop_id', $shopId))
            ->distinct()
            ->pluck('mapping.id');

        $plans = collect();

        foreach ($mappingIds as $mappingId) {
            try {
                $plans->push($this->plan((string) $mappingId));
            } catch (DomainException) {

            }
        }

        return $plans
            ->sortBy([
                ['channel', 'asc'],
                ['shop_name', 'asc'],
                ['listing', 'asc'],
            ])
            ->when($limit !== null, fn (Collection $items) => $items->take($limit))
            ->values();
    }

    public function split(string $mappingId): array
    {
        return DB::transaction(function () use ($mappingId): array {
            $plan = $this->plan($mappingId, true);
            $now = now();
            $movedModels = 0;
            $createdParents = 0;

            foreach ($plan->targets as $target) {
                $targetMappingId = $target->mapping_id;

                if ($targetMappingId === null) {
                    $targetMappingId = (string) Uuid::uuid7();
                    $createdParents++;

                    DB::table('product_channel_mappings')->insert([
                        'id' => $targetMappingId,
                        'product_id' => $target->product_id,
                        'channel_shop_id' => $plan->channel_shop_id,
                        'external_product_id' => $plan->listing,
                        'channel_attributes' => $plan->channel_attributes,
                        'channel_url' => $plan->channel_url,
                        'sync_status' => $plan->sync_status,
                        'error_message' => $plan->error_message,
                        'last_synced_at' => $plan->last_synced_at,
                        'reviewed_at' => $plan->reviewed_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $moved = DB::table('product_variant_channel_mappings')
                    ->whereIn('id', $target->child_ids)
                    ->update([
                        'product_channel_mapping_id' => $targetMappingId,
                        'updated_at' => $now,
                    ]);

                if ($moved !== count($target->child_ids)) {
                    throw new DomainException('Perbaikan dibatalkan: jumlah varian yang dipindahkan berubah saat transaksi berjalan.');
                }

                DB::table('channel_mapping_split_audits')->insert([
                    'id' => (string) Uuid::uuid7(),
                    'source_mapping_id' => $plan->mapping_id,
                    'target_mapping_id' => $targetMappingId,
                    'channel_shop_id' => $plan->channel_shop_id,
                    'external_product_id' => $plan->listing,
                    'source_product_id' => $plan->source_product_id,
                    'target_product_id' => $target->product_id,
                    'moved_models' => $moved,
                    'created_target_mapping' => $target->mapping_id === null,
                    'repaired_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $movedModels += $moved;
            }

            return [
                'mapping_id' => $plan->mapping_id,
                'channel' => $plan->channel,
                'shop_id' => $plan->shop_id,
                'shop_name' => $plan->shop_name,
                'listing' => $plan->listing,
                'moved_models' => $movedModels,
                'created_parents' => $createdParents,
            ];
        }, 3);
    }

    private function plan(string $mappingId, bool $lock = false): object
    {
        $mappingQuery = DB::table('product_channel_mappings as mapping')
            ->join('products as source_product', 'source_product.id', '=', 'mapping.product_id')
            ->join('channel_shops as shop', 'shop.id', '=', 'mapping.channel_shop_id')
            ->join('channels as channel', 'channel.id', '=', 'shop.channel_id')
            ->where('mapping.id', $mappingId)
            ->whereNull('source_product.deleted_at')
            ->where('source_product.is_active', true)
            ->where('mapping.sync_status', '<>', 'deactivated');

        if ($lock) {
            $mappingQuery->lockForUpdate();
        }

        $mapping = $mappingQuery->first([
            'mapping.id',
            'mapping.product_id as source_product_id',
            'mapping.channel_shop_id',
            'mapping.external_product_id as listing',
            'mapping.channel_attributes',
            'mapping.channel_url',
            'mapping.sync_status',
            'mapping.error_message',
            'mapping.last_synced_at',
            'mapping.reviewed_at',
            'channel.code as channel',
            'shop.shop_id',
            'shop.shop_name',
        ]);

        if ($mapping === null) {
            throw new DomainException('Pemecahan dibatalkan: mapping asal atau master asal tidak lagi aktif.');
        }

        $childrenQuery = DB::table('product_variant_channel_mappings as child')
            ->where('child.product_channel_mapping_id', $mapping->id);

        if ($lock) {
            $childrenQuery->lockForUpdate();
        }

        $childRows = $childrenQuery->get([
            'child.id',
            'child.variant_id',
            'child.external_sku_id',
            'child.channel_seller_sku',
        ]);

        if ($childRows->isEmpty()) {
            throw new DomainException('Pemecahan dibatalkan: listing tidak memiliki varian channel.');
        }

        $variantQuery = DB::table('product_variants')
            ->whereIn('id', $childRows->pluck('variant_id')->all());

        if ($lock) {
            $variantQuery->lockForUpdate();
        }

        $variants = $variantQuery->get([
            'id',
            'product_id',
            'is_active',
            'deleted_at',
        ])->keyBy('id');

        $productIds = $variants->pluck('product_id')->filter()->unique()->values()->all();
        $productQuery = DB::table('products')->whereIn('id', $productIds);

        if ($lock) {
            $productQuery->lockForUpdate();
        }

        $products = $productQuery->get([
            'id',
            'is_active',
            'deleted_at',
        ])->keyBy('id');

        $children = $childRows->map(function (object $child) use ($variants, $products): object {
            $variant = $variants->get($child->variant_id);
            $targetProduct = $variant === null ? null : $products->get($variant->product_id);

            return (object) [
                'id' => $child->id,
                'external_sku_id' => $child->external_sku_id,
                'channel_seller_sku' => $child->channel_seller_sku,
                'variant_id' => $variant?->id,
                'target_product_id' => $variant?->product_id,
                'variant_active' => $variant?->is_active,
                'variant_deleted_at' => $variant?->deleted_at,
                'target_product_active' => $targetProduct?->is_active,
                'target_product_deleted_at' => $targetProduct?->deleted_at,
            ];
        });

        if ($children->contains(fn (object $child): bool => $child->variant_id === null
            || $child->target_product_id === null
            || ! $child->variant_active
            || $child->variant_deleted_at !== null
            || ! $child->target_product_active
            || $child->target_product_deleted_at !== null)) {
            throw new DomainException('Pemecahan dibatalkan: terdapat varian atau master tujuan yang tidak aktif.');
        }

        $byOwner = $children->groupBy(fn (object $child): string => (string) $child->target_product_id);

        if ($byOwner->count() < 2 || ! $byOwner->has((string) $mapping->source_product_id)) {
            throw new DomainException('Pemecahan dibatalkan: listing tidak berisi campuran pemilik varian yang dapat diverifikasi.');
        }

        $targets = collect();

        foreach ($byOwner as $productId => $group) {
            if ($productId === (string) $mapping->source_product_id) {
                continue;
            }

            $targetQuery = DB::table('product_channel_mappings')
                ->where('channel_shop_id', $mapping->channel_shop_id)
                ->where('product_id', $productId)
                ->where('id', '<>', $mapping->id)
                ->where(function ($query) use ($mapping): void {
                    if ($mapping->listing === null) {
                        $query->whereNull('external_product_id');

                        return;
                    }

                    $query->where('external_product_id', $mapping->listing);
                });

            if ($lock) {
                $targetQuery->lockForUpdate();
            }

            $targetMappings = $targetQuery->get(['id', 'sync_status']);

            if ($targetMappings->count() > 1) {
                throw new DomainException('Pemecahan dibatalkan: terdapat lebih dari satu mapping tujuan untuk master yang sama.');
            }

            $targetMappingId = $targetMappings->first()?->id;

            if ($targetMappings->first()?->sync_status === 'deactivated') {
                throw new DomainException('Pemecahan dibatalkan: mapping tujuan sudah dinonaktifkan dan wajib ditinjau manual.');
            }

            if ($targetMappingId !== null && $this->hasTargetCollision((string) $targetMappingId, $group, $lock)) {
                throw new DomainException('Pemecahan dibatalkan: mapping tujuan sudah memiliki varian atau model marketplace yang sama.');
            }

            $targets->push((object) [
                'product_id' => $productId,
                'mapping_id' => $targetMappingId === null ? null : (string) $targetMappingId,
                'child_ids' => $group->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
            ]);
        }

        if ($targets->isEmpty()) {
            throw new DomainException('Pemecahan dibatalkan: tidak ada kelompok varian yang perlu dipindahkan.');
        }

        return (object) [
            'mapping_id' => (string) $mapping->id,
            'source_product_id' => (string) $mapping->source_product_id,
            'channel_shop_id' => (string) $mapping->channel_shop_id,
            'listing' => $mapping->listing,
            'channel_attributes' => $mapping->channel_attributes,
            'channel_url' => $mapping->channel_url,
            'sync_status' => $mapping->sync_status,
            'error_message' => $mapping->error_message,
            'last_synced_at' => $mapping->last_synced_at,
            'reviewed_at' => $mapping->reviewed_at,
            'channel' => $mapping->channel,
            'shop_id' => $mapping->shop_id,
            'shop_name' => $mapping->shop_name,
            'moved_models' => $targets->sum(fn (object $target): int => count($target->child_ids)),
            'created_parents' => $targets->whereNull('mapping_id')->count(),
            'targets' => $targets,
        ];
    }

    private function hasTargetCollision(string $targetMappingId, Collection $movingChildren, bool $lock): bool
    {
        $targetChildrenQuery = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $targetMappingId);

        if ($lock) {
            $targetChildrenQuery->lockForUpdate();
        }

        $targetChildren = $targetChildrenQuery->get(['variant_id', 'external_sku_id', 'channel_seller_sku']);

        foreach ($movingChildren as $child) {
            $collision = $targetChildren->contains(function (object $targetChild) use ($child): bool {
                if ((string) $targetChild->variant_id === (string) $child->variant_id) {
                    return true;
                }

                if ($child->external_sku_id !== null && $child->external_sku_id !== ''
                    && (string) $targetChild->external_sku_id === (string) $child->external_sku_id) {
                    return true;
                }

                return blank($child->external_sku_id)
                    && filled($child->channel_seller_sku)
                    && (string) $targetChild->channel_seller_sku === (string) $child->channel_seller_sku;
            });

            if ($collision) {
                return true;
            }
        }

        return false;
    }
}
