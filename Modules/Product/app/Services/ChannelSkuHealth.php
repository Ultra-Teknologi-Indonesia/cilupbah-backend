<?php

namespace Modules\Product\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Support\ChannelSku;

class ChannelSkuHealth
{
    private const HITUNG_MODEL = "count(DISTINCT COALESCE(psl.payload->>'external_sku_id', psl.id::text))";

    public function masterSkuTurunanVarian(?string $productId = null): Collection
    {
        return DB::table('products as p')
            ->join('product_variants as v', function ($join) {
                $join->on('v.sku', '=', 'p.sku')->whereNull('v.deleted_at');
            })
            ->where('p.is_from_channel', true)
            ->whereNotNull('p.sku')
            ->when($productId, fn ($q) => $q->where('p.id', $productId))
            ->select('p.id', 'p.name', 'p.sku')
            ->selectRaw('v.product_id = p.id AS varian_sendiri')
            ->distinct()
            ->get();
    }

    public function masterSkuTakDikenal(array $kecuali = [], ?string $productId = null): int
    {
        return DB::table('products')
            ->where('is_from_channel', true)
            ->whereNotNull('sku')
            ->when($kecuali, fn ($q) => $q->whereNotIn('id', $kecuali))
            ->when($productId, fn ($q) => $q->where('id', $productId))
            ->count();
    }

    public function varianPlaceholder(?string $productId = null): Collection
    {
        return DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('product_variant_channel_mappings as pvcm', 'pvcm.variant_id', '=', 'v.id')
            ->leftJoin('product_channel_mappings as pcm', 'pcm.id', '=', 'pvcm.product_channel_mapping_id')
            ->whereNull('v.deleted_at')
            ->whereNotNull('v.sku')
            ->when($productId, fn ($q) => $q->where('v.product_id', $productId))
            ->where(fn ($q) => $q->whereNotNull('pcm.id')->orWhere('p.is_from_channel', true))
            ->where(function ($q) {
                $q->whereRaw("v.sku !~ '[[:alnum:]]'")
                    ->orWhereRaw("v.sku ~ '^[0-9]+-[0-9-]+$'");
            })
            ->select('v.id', 'v.sku', 'v.product_id', 'pcm.external_product_id')
            ->get()
            ->filter(fn ($row) => ChannelSku::isPlaceholder($row->sku, $row->external_product_id))
            ->unique('id')
            ->values();
    }

    public function listingTerpecah(?Collection $shopIds = null): int
    {
        return $this->multiMasterListingDetails($shopIds)
            ->where('valid_bundle_split', false)
            ->count();
    }

    public function multiMasterListingDetails(?Collection $shopIds = null): Collection
    {
        $rows = DB::table('product_channel_mappings as pcm')
            ->join('products as p', 'p.id', '=', 'pcm.product_id')
            ->leftJoin('product_variant_channel_mappings as pvcm', 'pvcm.product_channel_mapping_id', '=', 'pcm.id')
            ->when($shopIds, fn ($q) => $q->whereIn('pcm.channel_shop_id', $shopIds->all()))
            ->whereNotNull('pcm.external_product_id')
            ->select([
                'pcm.id as pcm_id',
                'pcm.channel_shop_id',
                'pcm.external_product_id',
                'pcm.product_id',
                'p.is_bundle',
                'p.sku as master_sku',
                'pvcm.channel_seller_sku',
            ])
            ->get();

        return $rows
            ->groupBy(fn ($row) => $row->channel_shop_id.'|'.$row->external_product_id)
            ->filter(fn (Collection $listing) => $listing->pluck('product_id')->unique()->count() > 1)
            ->map(function (Collection $listing) {
                $byMapping = $listing->groupBy('pcm_id');
                $validBundleSplit = $byMapping->every(function (Collection $mapping) {
                    $first = $mapping->first();
                    $sellerSkus = $mapping->pluck('channel_seller_sku')->filter()->unique();

                    return (bool) $first->is_bundle
                        && filled($first->master_sku)
                        && $sellerSkus->isNotEmpty()
                        && $sellerSkus->every(fn ($sku) => $sku === $first->master_sku);
                });

                $first = $listing->first();

                return (object) [
                    'channel_shop_id' => $first->channel_shop_id,
                    'external_product_id' => $first->external_product_id,
                    'jml_master' => $listing->pluck('product_id')->unique()->count(),
                    'valid_bundle_split' => $validBundleSplit,
                ];
            })
            ->values();
    }

    public function modelTanpaSku(int $jam = 24, ?string $channel = null): Collection
    {
        return DB::table('product_sync_logs as psl')
            ->join('channel_shops as cs', 'cs.id', '=', 'psl.channel_shop_id')
            ->join('channels as c', 'c.id', '=', 'cs.channel_id')
            ->leftJoin('product_channel_mappings as pcm', function ($join) {
                $join->on('pcm.channel_shop_id', '=', 'psl.channel_shop_id')
                    ->on('pcm.external_product_id', '=', DB::raw("psl.payload->>'external_product_id'"));
            })
            ->where('psl.action', 'download')
            ->where('psl.status', 'failed')
            ->where('psl.created_at', '>', now()->subHours($jam))
            ->when($channel, fn ($q) => $q->where('c.code', strtolower($channel)))
            ->whereRaw("psl.payload->>'external_product_id' IS NOT NULL")
            ->groupBy('c.code', 'cs.shop_name', 'cs.shop_id')
            ->groupByRaw("psl.payload->>'external_product_id'")
            ->selectRaw("c.code AS channel, cs.shop_name, cs.shop_id, psl.payload->>'external_product_id' AS listing, ".self::HITUNG_MODEL.' AS jml, max(pcm.channel_url) AS url')
            ->orderByDesc(DB::raw(self::HITUNG_MODEL))
            ->get();
    }
}
