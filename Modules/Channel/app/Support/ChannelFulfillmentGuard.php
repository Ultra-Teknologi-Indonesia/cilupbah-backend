<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelShopRepository;

class ChannelFulfillmentGuard
{
    public static function blocks(?string $channelShopId, string $action, ?string $reference = null): bool
    {
        $channelShopId = trim((string) $channelShopId);

        if ($channelShopId === '') {
            return false;
        }

        $shop = app(ChannelShopRepository::class)->findByShopId($channelShopId);

        if (! $shop || $shop->fulfillment_push_enabled) {
            return false;
        }

        Log::info('Aksi fulfillment ke marketplace dilewati: push fulfillment untuk toko ini dimatikan.', [
            'channel_shop_id' => $channelShopId,
            'action'          => $action,
            'reference'       => $reference,
            'is_shadow_mode'  => (bool) $shop->is_shadow_mode,
        ]);

        return true;
    }
}
