<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FailedSyncResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'product_id'          => $this->product_id,
            'channel_shop_id'     => $this->channel_shop_id,
            'product_name'        => $this->product?->name,
            'sku'                 => $this->product?->sku,
            'thumbnail'           => $this->product?->thumbnail,
            'channel_name'        => $this->channelShop?->channel?->name,
            'shop_name'           => $this->channelShop?->shop_name,
            'sync_status'         => $this->sync_status,
            'error_message'       => $this->error_message,
            'last_synced_at'      => $this->last_synced_at?->toISOString(),
            'external_product_id' => $this->external_product_id,
        ];
    }
}
