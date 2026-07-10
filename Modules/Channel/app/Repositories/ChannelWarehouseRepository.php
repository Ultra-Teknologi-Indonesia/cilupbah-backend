<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;

class ChannelWarehouseRepository
{
    public function saveWarehouseMapping(string $storeId, string $channelId, string $channelLocationId, ?string $channelLocationType): void
    {
        $exists = DB::table('channel_warehouses')->where('store_id', $storeId)->exists();

        if ($exists) {
            DB::table('channel_warehouses')
                ->where('store_id', $storeId)
                ->update([
                    'channel_location_id' => $channelLocationId,
                    'channel_location_type' => $channelLocationType,
                    'updated_at' => now(),
                ]);
            return;
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
            return;
        }

        DB::table('channel_warehouses')->insert([
            'location_id' => $defaultLocation->id,
            'channel_id' => $channelId,
            'store_id' => $storeId,
            'channel_location_id' => $channelLocationId,
            'channel_location_type' => $channelLocationType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
