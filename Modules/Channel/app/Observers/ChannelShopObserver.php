<?php

namespace Modules\Channel\Observers;

use Modules\Channel\Models\ChannelShop;
use Modules\Supplier\Models\Contact;
use Modules\Supplier\Models\ContactCategory;
use Illuminate\Support\Str;

class ChannelShopObserver
{
    public function saved(ChannelShop $shop): void
    {
        if ($shop->disconnected_at !== null) {
            return;
        }

        $shop->loadMissing('channel');
        $channel = $shop->channel;
        if (!$channel) {
            return;
        }

        $code = 'MP-' . Str::upper($channel->code);
        $category = ContactCategory::where('code', 'PLG-UMUM')->first();

        Contact::firstOrCreate(
            ['code' => $code],
            [
                'name'        => $channel->name,
                'type'        => Contact::TYPE_BOTH,
                'category_id' => $category?->id,
                'is_system'   => true,
                'is_company'  => true,
                'status'      => Contact::STATUS_ACTIVE,
            ]
        );
    }
}
