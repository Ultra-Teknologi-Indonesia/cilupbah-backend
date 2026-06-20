<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;

class ChannelStockResolver
{

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
