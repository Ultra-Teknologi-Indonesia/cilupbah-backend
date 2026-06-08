<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductChannelDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'channel_shop_id' => $this->channel_shop_id,
            'shop_name' => $this->whenLoaded('channelShop', fn () => $this->channelShop->shop_name ?? null),
            'channel_category_id' => $this->channel_category_id,
            'attribute_mapping' => $this->attribute_mapping,
            'price_override' => $this->price_override,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
