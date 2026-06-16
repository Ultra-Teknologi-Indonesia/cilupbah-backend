<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductChannelPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $channel = $request->input('filter.channel');

        $prices = collect($this->relationLoaded('channelMappings') ? $this->channelMappings : [])
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
                    'channel_code' => $ch->code ?? null,
                    'price' => $m->override_price ?? $m->synced_price,
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
            'internal_price' => $this->sell_price,
            'prices' => $prices,
        ];
    }
}
