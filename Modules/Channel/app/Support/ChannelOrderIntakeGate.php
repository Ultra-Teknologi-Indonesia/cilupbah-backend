<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\DB;

class ChannelOrderIntakeGate
{
    public static function blocksShop(string $shopId, ?string $channelCode = null): bool
    {
        if ($shopId === '') {
            return false;
        }

        $enabled = DB::table('channel_shops')
            ->where('shop_id', $shopId)
            ->when($channelCode, fn ($query, $code) => $query->whereIn(
                'channel_id',
                DB::table('channels')->select('id')->where('code', $code),
            ))
            ->value('order_sync_enabled');

        if ($enabled === null) {
            return false;
        }

        return ! (bool) $enabled;
    }

    public static function reason(): string
    {
        return 'Sinkron pesanan toko ini dimatikan — event tidak diproses.';
    }
}
