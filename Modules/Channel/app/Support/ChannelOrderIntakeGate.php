<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;

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

    public static function shouldDeferOrderEvent(string $channel, string $shopId, string $orderId): bool
    {
        $orderId = trim($orderId);

        if ($orderId === '' || ! self::blocksShop($shopId, $channel)) {
            return false;
        }

        return ! SalesOrder::query()
            ->where('source', strtolower(trim($channel)))
            ->where('channel_order_no', $orderId)
            ->exists();
    }

    public static function deferredReason(): string
    {
        return 'ORDER_INTAKE_DEFERRED: Sinkron order ditunda karena penerimaan order toko dimatikan; akan diproses otomatis setelah intake dibuka.';
    }

    public static function isDeferredReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, 'ORDER_INTAKE_DEFERRED:');
    }
}
