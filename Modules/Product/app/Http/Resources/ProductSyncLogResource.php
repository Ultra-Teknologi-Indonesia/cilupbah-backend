<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSyncLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shop = $this->relationLoaded('channelShop') ? $this->channelShop : null;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name ?? null),
            'sku' => $this->whenLoaded('product', fn () => $this->product->sku ?? null),
            'channel_shop_id' => $this->channel_shop_id,
            'shop_name' => $shop->shop_name ?? null,
            'channel_name' => ($shop && $shop->relationLoaded('channel') && $shop->channel)
                ? $shop->channel->name
                : null,
            'action' => $this->action,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'response' => $this->response,
            'created_at' => $this->created_at,
        ];
    }
}
