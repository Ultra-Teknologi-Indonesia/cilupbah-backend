<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Support\ChannelUrlBuilder;

class UploadHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shop = $this->relationLoaded('channelShop') ? $this->channelShop : null;
        $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;
        $success = $this->status === ProductSyncLog::STATUS_SUCCESS;

        return [
            'id' => $this->id,
            'item_group_id' => $this->product_id,
            'item_group_name' => $this->whenLoaded('product', fn () => $this->product->name ?? null),
            'thumbnail' => $this->thumbnail(),
            'upload_date' => $this->created_at,
            'success' => $success,
            'status_message' => $this->statusMessage(),
            'can_reupload' => $this->status === ProductSyncLog::STATUS_FAILED,
            'max' => $shop->shop_name ?? null,
            'channel_code' => $channel->code ?? null,
            'channel_name' => $channel->name ?? null,
            'channel_id' => $shop->channel_id ?? null,
            'store_id' => $this->channel_shop_id,
            'active_store' => $shop ? (bool) $shop->is_active : null,
            'channel_url' => $this->channelUrl($channel, $shop),
            'products' => $this->products(),
        ];
    }

    protected function statusMessage(): string
    {
        return match ($this->status) {
            ProductSyncLog::STATUS_SUCCESS => 'Sukses',
            ProductSyncLog::STATUS_FAILED => $this->error_message ?: 'Gagal',
            default => 'Sedang diproses',
        };
    }

    protected function products(): array
    {
        if (! $this->relationLoaded('product') || ! $this->product) {
            return [];
        }

        if (! $this->product->relationLoaded('variants')) {
            return [];
        }

        $name = $this->product->name;

        return $this->product->variants
            ->map(fn ($variant) => [
                'item_name' => $name,
                'item_code' => $variant->sku,
            ])
            ->values()
            ->all();
    }

    protected function thumbnail(): ?string
    {
        if (! $this->relationLoaded('product') || ! $this->product || ! $this->product->relationLoaded('media')) {
            return null;
        }

        $media = $this->product->media;
        $primary = $media->whereNull('variant_id')->firstWhere('is_primary', true)
            ?? $media->whereNull('variant_id')->first()
            ?? $media->first();

        return $primary->url ?? null;
    }

    protected function channelUrl($channel, $shop): ?string
    {
        if (! $this->relationLoaded('product') || ! $this->product || ! $this->product->relationLoaded('channelMappings')) {
            return null;
        }

        $mapping = $this->product->channelMappings
            ->firstWhere('channel_shop_id', $this->channel_shop_id);

        if ($mapping && $mapping->channel_url) {
            return $mapping->channel_url;
        }

        return ChannelUrlBuilder::build(
            $channel->code ?? null,
            $mapping->external_product_id ?? null,
            $shop->shop_id ?? null,
        );
    }
}
