<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventorySyncMatrixResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->id,
            'item_code' => $this->sku,
            'item_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'item_group_id' => $this->product_id,
            'is_bundle' => $this->whenLoaded('product', fn () => (bool) $this->product?->is_bundle, false),
            'internal_stock' => [
                'on_hand' => (int) data_get($this->internal_stock, 'on_hand', 0),
                'on_order' => (int) data_get($this->internal_stock, 'on_order', 0),
                'available' => (int) data_get($this->internal_stock, 'available', 0),
            ],
            'variation_values' => $this->variationValues(),
            'thumbnail' => $this->resolveThumbnail(),
            'stores' => $this->stores(),
        ];
    }

    protected function stores(): array
    {
        if (! $this->relationLoaded('channelMappings')) {
            return [];
        }

        return $this->channelMappings
            ->filter(fn ($mapping) => $mapping->channelMapping !== null)
            ->map(fn ($mapping) => [
                'mapping_id' => $mapping->channelMapping->id,
                'channel_shop_id' => $mapping->channelMapping->channel_shop_id,
                'has_listing' => true,
                'sync_enabled' => (bool) $mapping->sync_enabled,
                'external_sku_id' => $mapping->external_sku_id,
                'sync_status' => $mapping->channelMapping->sync_status,
                'stock_sync' => $this->stockSync($mapping->channelMapping),
            ])
            ->values()
            ->all();
    }

    protected function stockSync($mapping): array
    {
        $outbox = $mapping->relationLoaded('stockSyncOutbox')
            ? $mapping->stockSyncOutbox
            : null;

        $status = match ($outbox?->status) {
            'succeeded' => 'success',
            'dispatching', 'pending' => 'processing',
            'failed' => 'failed',
            'skipped' => 'skipped',
            default => match ($mapping->sync_status) {
                'synced' => 'success',
                'syncing', 'pending' => 'processing',
                'failed', 'rejected' => 'failed',
                default => 'idle',
            },
        };

        return [
            'status' => $status,
            'outbox_status' => $outbox?->status,
            'last_error' => $outbox?->last_error ?: $mapping->error_message,
            'attempt_count' => (int) ($outbox?->attempt_count ?? 0),
            'next_attempt_at' => $outbox?->next_attempt_at?->toIso8601String(),
            'updated_at' => $outbox?->updated_at?->toIso8601String() ?? $mapping->last_synced_at?->toIso8601String(),
        ];
    }

    protected function variationValues(): array
    {
        if (! $this->relationLoaded('options')) {
            return [];
        }

        return $this->options->map(fn ($opt) => [
            'label' => $opt->relationLoaded('attribute') ? $opt->attribute?->name : null,
            'value' => $opt->value,
        ])->values()->toArray();
    }

    protected function resolveThumbnail(): ?string
    {
        if ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            $primary = $this->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->media->first()->url;
        }

        if ($this->relationLoaded('product') && $this->product?->relationLoaded('media') && $this->product->media->isNotEmpty()) {
            $primary = $this->product->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->product->media->first()->url;
        }

        return null;
    }
}
