<?php

namespace Modules\Channel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'integration' => $this->integrationStatus(),
            'token_expires_at' => $this->token_expires_at,
            'channel' => $this->whenLoaded('channel', fn () => $this->channel ? [
                'id' => $this->channel->id,
                'code' => $this->channel->code,
                'name' => $this->channel->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** Status integrasi diturunkan dari token (bukan dari is_active). */
    protected function integrationStatus(): array
    {
        $needReauth = empty($this->access_token)
            || ($this->refresh_token_expires_at && $this->refresh_token_expires_at->isPast());

        return $needReauth
            ? ['status' => 'error', 'note' => 'Perlu otorisasi ulang']
            : ['status' => 'normal'];
    }
}
