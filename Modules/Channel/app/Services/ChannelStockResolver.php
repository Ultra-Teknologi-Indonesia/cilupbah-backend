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

        $locationId = $this->resolveLocationId($shop);
        if (! $locationId) {
            return $result;
        }

        $stocks = DB::table('inventories')
            ->whereIn('item_id', $variantIds)
            ->where('location_id', $locationId)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(on_hand) as oh, SUM(on_order) as oo, SUM(reserved) as r')
            ->get();

        foreach ($stocks as $row) {
            $qty = (int) $row->oh - (int) $row->oo - (int) $row->r;
            $result[$row->item_id] = max(0, $qty);
        }

        return $result;
    }

    protected function resolveLocationId(ChannelShop $shop): ?string
    {
        $cw = DB::table('channel_warehouses')->where('store_id', $shop->shop_id)->first();
        if ($cw) {
            return $cw->location_id;
        }

        $defaultLocation = DB::table('locations')
            ->where('is_warehouse', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('location_type')
                    ->orWhere('location_type', '!=', 'TRANSIT');
            })
            ->orderBy('created_at')
            ->first();

        if (! $defaultLocation) {
            return null;
        }

        DB::table('channel_warehouses')->insert([
            'location_id' => $defaultLocation->id,
            'channel_id' => $shop->channel_id,
            'store_id' => $shop->shop_id,
            'channel_location_id' => null,
            'channel_location_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $defaultLocation->id;
    }
}
