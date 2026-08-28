<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\ChannelUrlBuilder;

class ProductChannelListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $channel = $request->input('filter.channel');
        $shopId = $request->input('filter.shop_id');

        $listings = collect($this->relationLoaded('channelMappings') ? $this->channelMappings : [])
            ->map(function ($m) {
                $cm = $m->relationLoaded('channelMapping') ? $m->channelMapping : null;
                if (! $cm) {
                    return null;
                }
                $shop = $cm->relationLoaded('channelShop') ? $cm->channelShop : null;
                $ch = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

                return [
                    'product_channel_mapping_id' => $cm->id,
                    'variant_channel_mapping_id' => $m->id,
                    'channel_shop_id' => $cm->channel_shop_id,
                    'marketplace_shop_id' => $shop->shop_id ?? null,
                    'shop_name' => $shop->shop_name ?? null,
                    'channel_name' => $ch->name ?? null,
                    'channel_code' => $ch->code ?? null,
                    'external_product_id' => $cm->external_product_id,
                    'external_sku_id' => $m->external_sku_id,
                    'channel_url' => $cm->channel_url ?: ChannelUrlBuilder::build(
                        $ch->code ?? null,
                        $cm->external_product_id,
                        $shop->shop_id ?? null,
                        $m->external_sku_id
                    ),
                    'sync_status' => $cm->sync_status,
                    'error_message' => $cm->error_message,
                    'last_synced_at' => $cm->last_synced_at,
                ];
            })
            ->filter()
            ->when($channel, fn ($c) => $c->where('channel_code', $channel))
            ->when($shopId, fn ($c) => $c->where('channel_shop_id', $shopId))
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
