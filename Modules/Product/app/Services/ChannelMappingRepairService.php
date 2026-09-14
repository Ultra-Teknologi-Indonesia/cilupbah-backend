<?php

namespace Modules\Product\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ChannelMappingRepairService
{
    public function safeParentReassignments(?string $channel = null, ?string $shopId = null, ?int $limit = null): Collection
    {
        return $this->safeCandidates($channel, $shopId)
            ->orderBy('channel')
            ->orderBy('shop_name')
            ->orderBy('listing')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }

    public function reassign(string $mappingId): array
    {
        return DB::transaction(function () use ($mappingId): array {
            $mapping = DB::table('product_channel_mappings as mapping')
                ->join('products as old_product', 'old_product.id', '=', 'mapping.product_id')
                ->where('mapping.id', $mappingId)
                ->whereNull('old_product.deleted_at')
                ->where('old_product.is_active', true)
                ->lockForUpdate()
                ->first([
                    'mapping.id',
                    'mapping.product_id as old_product_id',
                    'mapping.channel_shop_id',
                    'mapping.external_product_id',
                ]);

            if (! $mapping) {
                throw new DomainException('Perbaikan dibatalkan: listing atau master asal tidak lagi aktif.');
            }

            $children = DB::table('product_variant_channel_mappings as child')
                ->join('product_variants as variant', 'variant.id', '=', 'child.variant_id')
                ->join('products as target_product', 'target_product.id', '=', 'variant.product_id')
                ->where('child.product_channel_mapping_id', $mapping->id)
                ->lockForUpdate()
                ->get([
                    'child.id',
                    'variant.product_id as target_product_id',
                    'variant.is_active as variant_active',
                    'variant.deleted_at as variant_deleted_at',
                    'target_product.is_active as target_product_active',
                    'target_product.deleted_at as target_product_deleted_at',
                ]);

            if ($children->isEmpty()) {
                throw new DomainException('Perbaikan dibatalkan: listing tidak memiliki varian channel.');
            }

            if ($children->contains(fn (object $child): bool => ! $child->variant_active
                || $child->variant_deleted_at !== null
                || ! $child->target_product_active
                || $child->target_product_deleted_at !== null)) {
                throw new DomainException('Perbaikan dibatalkan: terdapat varian atau master tujuan yang tidak aktif.');
            }

            $targets = $children->pluck('target_product_id')->map('strval')->unique()->values();

            if ($targets->count() !== 1 || $targets->first() === (string) $mapping->old_product_id) {
                throw new DomainException('Perbaikan dibatalkan: listing tidak sepenuhnya mengarah ke satu master tujuan baru.');
            }

            $targetProductId = $targets->first();
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
                throw new DomainException('Perbaikan dibatalkan: listing tersebut sudah memiliki tautan pada master tujuan.');
            }

            DB::table('product_channel_mappings')
                ->where('id', $mapping->id)
                ->update(['product_id' => $targetProductId, 'updated_at' => now()]);

            DB::table('channel_mapping_repair_audits')->insert([
                'id' => (string) Uuid::uuid7(),
                'mapping_id' => $mapping->id,
                'channel_shop_id' => $mapping->channel_shop_id,
                'external_product_id' => $mapping->external_product_id,
                'old_product_id' => $mapping->old_product_id,
                'new_product_id' => $targetProductId,
                'models' => $children->count(),
                'repaired_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'mapping_id' => (string) $mapping->id,
                'old_product_id' => (string) $mapping->old_product_id,
                'target_product_id' => $targetProductId,
                'models' => $children->count(),
            ];
        }, 3);
    }

    private function safeCandidates(?string $channel, ?string $shopId)
    {
        return DB::table('product_channel_mappings as mapping')
            ->join('products as old_product', 'old_product.id', '=', 'mapping.product_id')
            ->join('product_variant_channel_mappings as child', 'child.product_channel_mapping_id', '=', 'mapping.id')
            ->join('product_variants as variant', 'variant.id', '=', 'child.variant_id')
            ->join('products as target_product', 'target_product.id', '=', 'variant.product_id')
            ->join('channel_shops as shop', 'shop.id', '=', 'mapping.channel_shop_id')
            ->join('channels as channel', 'channel.id', '=', 'shop.channel_id')
            ->whereNull('old_product.deleted_at')
            ->where('old_product.is_active', true)
            ->whereNull('variant.deleted_at')
            ->where('variant.is_active', true)
            ->whereNull('target_product.deleted_at')
            ->where('target_product.is_active', true)
            ->when($channel !== null && $channel !== '', fn ($query) => $query->where('channel.code', $channel))
            ->when($shopId !== null && $shopId !== '', fn ($query) => $query->where('shop.shop_id', $shopId))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('product_channel_mappings as duplicate')
                    ->whereColumn('duplicate.channel_shop_id', 'mapping.channel_shop_id')
                    ->whereColumn('duplicate.product_id', 'variant.product_id')
                    ->whereColumn('duplicate.id', '<>', 'mapping.id')
                    ->whereRaw("COALESCE(duplicate.external_product_id, '') = COALESCE(mapping.external_product_id, '')");
            })
            ->selectRaw('mapping.id AS mapping_id, channel.code AS channel, shop.shop_id, shop.shop_name, mapping.external_product_id AS listing, mapping.product_id AS old_product_id, old_product.name AS old_product, MIN(variant.product_id::text) AS target_product_id, MIN(target_product.name) AS target_product, COUNT(DISTINCT child.id) AS models')
            ->groupBy('mapping.id', 'channel.code', 'shop.shop_id', 'shop.shop_name', 'mapping.external_product_id', 'mapping.product_id', 'old_product.name')
            ->havingRaw('COUNT(DISTINCT child.id) = COUNT(DISTINCT child.id) FILTER (WHERE variant.product_id <> mapping.product_id)')
            ->havingRaw('COUNT(DISTINCT variant.product_id) = 1');
    }
}
