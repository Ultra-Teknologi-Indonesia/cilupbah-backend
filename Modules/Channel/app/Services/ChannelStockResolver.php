<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;

/**
 * Resolusi stok tersedia (available) SISTEM KITA per varian untuk satu toko channel.
 *
 * Arah stok = sistem kita → channel (saat naikkan/push & sinkron), BUKAN sebaliknya.
 * Sumber: inventories.available pada lokasi gudang yang dipetakan ke toko (channel_warehouses).
 * Bila toko belum dipetakan ke gudang → 0 (sama dengan perilaku syncPriceAndStock).
 */
class ChannelStockResolver
{
    /**
     * @param  iterable  $variants  koleksi ProductVariant (punya ->id)
     * @return array<string,int>  variantId => available qty (>= 0)
     */
    public function availableByVariant(ChannelShop $shop, iterable $variants): array
    {
        $variantIds = collect($variants)->pluck('id')->filter()->values()->all();
        $result = array_fill_keys($variantIds, 0);

        if (empty($variantIds)) {
            return $result;
        }

        $channelWarehouse = DB::table('channel_warehouses')->where('store_id', $shop->shop_id)->first();
        if (! $channelWarehouse) {
            return $result;
        }

        $stocks = DB::table('inventories')
            ->whereIn('item_id', $variantIds)
            ->where('location_id', $channelWarehouse->location_id)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(available) as qty')
            ->pluck('qty', 'item_id');

        foreach ($variantIds as $variantId) {
            $result[$variantId] = max(0, (int) ($stocks[$variantId] ?? 0));
        }

        return $result;
    }
}
