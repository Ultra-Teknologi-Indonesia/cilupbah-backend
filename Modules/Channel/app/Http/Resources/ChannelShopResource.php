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
            'stock_source_mode' => $this->stock_source_mode ?? 'location',
            'location_id' => $this->stock_source_mode === 'total' ? null : $this->stock_source_location_id,
            'location_name' => $this->stock_source_mode === 'total' ? null : optional($this->stockSourceLocation)->location_name,
            'location_code' => $this->stock_source_mode === 'total' ? null : optional($this->stockSourceLocation)->location_code,
            'integration' => $this->integrationStatus(),
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
        if (! $this->is_active || ! $this->access_token) {
            return 'disconnected';
        }

        if (! $this->token_expires_at) {
            return 'active';
        }

        if ($this->token_expires_at->isPast()) {
            return 'expired';
        }

        if ($this->token_expires_at->lt(now()->addHours(24))) {
            return 'expiring_soon';
        }

        return 'active';
    }

    protected function integrationStatus(): array
    {

        if ($this->integration_status === 'error') {
            return ['status' => 'error', 'note' => $this->last_error ?: 'Integrasi bermasalah'];
        }

        $needReauth = empty($this->access_token)
            || ($this->refresh_token_expires_at && $this->refresh_token_expires_at->isPast());
        if ($needReauth) {
            return ['status' => 'error', 'note' => 'Perlu otorisasi ulang'];
        }

        if ($this->token_expires_at && $this->token_expires_at->isPast()) {
            return ['status' => 'warning', 'note' => 'Token akses kedaluwarsa, akan diperbarui otomatis'];
        }

        if ($this->token_expires_at
            && $this->token_expires_at->isFuture()
            && now()->diffInHours($this->token_expires_at) < 24) {
            return ['status' => 'warning', 'note' => 'Token akan kedaluwarsa < 24 jam'];
        }

        if ($this->integration_status === 'warning') {
            return ['status' => 'warning', 'note' => $this->last_error ?: 'Perlu perhatian'];
        }

        return ['status' => 'normal'];
    }
}
