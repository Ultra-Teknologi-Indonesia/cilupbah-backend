<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelWarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'channel_id' => $this->channel_id,
            'store_id' => $this->store_id,
            'channel_location_id' => $this->channel_location_id,
            'channel_location_type' => $this->channel_location_type,
            'location' => $this->whenLoaded('location'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
