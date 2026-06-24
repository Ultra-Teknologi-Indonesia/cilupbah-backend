<?php

namespace Modules\Channel\Observers;

use Modules\Channel\Models\ChannelShop;
use Modules\Supplier\Models\Contact;
use Illuminate\Support\Str;

class ChannelShopObserver
{
    public function saved(ChannelShop $shop): void
    {
        if ($shop->disconnected_at !== null) {
            return;
        }

        $shop->loadMissing('channel');
        $channelName = $shop->channel?->name ?? 'Marketplace';

        $contactName = $channelName . ' - ' . $shop->shop_name;
        $code = 'MP-' . Str::upper(Str::substr($shop->channel?->code ?? 'mp', 0, 4)) . '-' . Str::upper(Str::substr($shop->shop_id, 0, 8));

        Contact::firstOrCreate(
            [
                'code' => $code,
            ],
            [
                'name'       => $contactName,
                'type'       => Contact::TYPE_SUPPLIER,
                'is_system'  => true,
                'status'     => Contact::STATUS_ACTIVE,
            ]
        );
    }
}
