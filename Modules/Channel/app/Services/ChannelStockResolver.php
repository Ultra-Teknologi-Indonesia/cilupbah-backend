<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Warehouse\Models\Location;

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
            ->selectRaw('item_id, SUM(on_hand) as oh, SUM(reserved) as r')
            ->get();

        foreach ($stocks as $row) {
            $qty = (int) $row->oh - (int) $row->r;
            $result[$row->item_id] = max(0, $qty);
        }

        return $result;
    }

    /**
     * Semua stok yang di-broadcast ke marketplace channel harus berasal dari
     * Gudang Kecil, terlepas dari mapping historis di channel_warehouses.
     *
     * $shop tidak dipakai lagi untuk memilih lokasi — dipertahankan di
     * signature supaya kompatibel dengan pemanggil existing.
     */
    protected function resolveLocationId(ChannelShop $shop): ?string
    {
        return DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id');
    }
}
