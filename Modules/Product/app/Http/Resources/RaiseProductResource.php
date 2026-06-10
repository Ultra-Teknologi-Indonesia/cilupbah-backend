<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RaiseProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shop = $this->resource->relationLoaded('channelShop') ? $this->channelShop : null;
        $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

        return [
            'raiseproduct_id' => $this->id,
            'store_id' => $this->channel_shop_id,
            'channel_id' => $shop->channel_id ?? null,
            'channel_code' => $channel->code ?? null,
            'channel_name' => $channel->name ?? null,
            'store_name' => $shop->shop_name ?? null,
            'product_active' => (int) ($this->product_active_count ?? 0),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
