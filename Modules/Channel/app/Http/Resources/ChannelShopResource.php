<?php

namespace Modules\Channel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Channel\Services\OrderSyncStatusService;
use Modules\Channel\Support\ChannelTokenStatus;

class ChannelShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'shop_name' => $this->shop_name,
            'is_active' => (bool) $this->is_active,
            'order_sync_enabled' => (bool) $this->order_sync_enabled,
            'is_shadow_mode' => (bool) $this->is_shadow_mode,
            'shadow_started_at' => $this->shadow_started_at,
            'shadow_last_pulled_at' => $this->shadow_last_pulled_at,
            'stock_source_mode' => $this->stock_source_mode ?? 'location',
            'location_id' => $this->stock_source_mode === 'total' ? null : $this->stock_source_location_id,
            'location_name' => $this->stock_source_mode === 'total' ? null : optional($this->stockSourceLocation)->location_name,
            'location_code' => $this->stock_source_mode === 'total' ? null : optional($this->stockSourceLocation)->location_code,
            'integration' => $this->integrationStatus(),
            'order_sync' => (new OrderSyncStatusService)->derive($this->resource),
            'last_order_synced_at' => $this->last_order_synced_at,
            'token_status' => $this->tokenStatus(),
            'token_expires_at' => $this->token_expires_at,
            'last_synced_at' => $this->last_synced_at,
            'channel' => $this->whenLoaded('channel', fn () => $this->channel ? [
                'id' => $this->channel->id,
                'code' => $this->channel->code,
                'name' => $this->channel->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function tokenStatus(): string
    {
        return ChannelTokenStatus::status($this->resource);
    }

    protected function integrationStatus(): array
    {
        return ChannelTokenStatus::integration($this->resource);
    }
}
