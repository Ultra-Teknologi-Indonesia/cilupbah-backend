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

        $locationIds = $this->sourceLocationIds($shop);
        if (empty($locationIds)) {
            return $result;
        }

        $stocks = DB::table('inventories')
            ->whereIn('item_id', $variantIds)
            ->whereIn('location_id', $locationIds)
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
     * Sumber stok available yang di-broadcast ke marketplace, per toko:
     * - mode 'total'    → semua gudang aktif (kecuali lokasi sistem transit)
     * - mode 'location' → satu gudang pilihan admin, fallback ke Gudang Kecil
     *   kalau belum diset / gudangnya sudah terhapus.
     */
    public function sourceLocationIds(ChannelShop $shop): array
    {
        if ($shop->stock_source_mode === 'total') {
            return DB::table('locations')
                ->where('is_warehouse', true)
                ->where('is_active', true)
                ->where('location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
                ->pluck('id')
                ->all();
        }

        $id = $shop->stock_source_location_id
            ?: DB::table('locations')->where('location_code', Location::SYSTEM_KECIL_CODE)->value('id');

        return $id ? [$id] : [];
    }
}
