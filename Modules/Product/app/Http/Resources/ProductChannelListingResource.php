<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris tab "Channel": satu varian + daftar toko/channel tempat ia ter-listing.
 * Filter ?channel=<code> mempersempit listings ke channel itu.
 */
class ProductChannelListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $channel = $request->query('channel');

        $listings = collect($this->relationLoaded('channelMappings') ? $this->channelMappings : [])
            ->map(function ($m) {
                $cm = $m->relationLoaded('channelMapping') ? $m->channelMapping : null;
                if (! $cm) {
                    return null;
                }
                $shop = $cm->relationLoaded('channelShop') ? $cm->channelShop : null;
                $ch = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

                return [
                    'channel_shop_id' => $cm->channel_shop_id,
                    'shop_name' => $shop->shop_name ?? null,
                    'channel_name' => $ch->name ?? null,
                    'channel_code' => $ch->code ?? null,
                    'external_product_id' => $cm->external_product_id,
                    'external_sku_id' => $m->external_sku_id,
                    'sync_status' => $cm->sync_status,
                    'last_synced_at' => $cm->last_synced_at,
                ];
            })
            ->filter()
            ->when($channel, fn ($c) => $c->where('channel_code', $channel))
            ->values();

        return [
            'variant_id' => $this->id,
            'sku' => $this->sku,
            'options' => $this->whenLoaded('options', fn () => $this->options
                ->map(fn ($o) => ['attribute_id' => $o->attribute_id, 'value' => $o->value])->values()),
            'listings' => $listings,
        ];
    }
}
