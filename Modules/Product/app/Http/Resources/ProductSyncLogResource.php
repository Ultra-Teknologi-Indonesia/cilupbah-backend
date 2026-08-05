<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Channel\Support\UploadErrorPresenter;
use Modules\Product\Models\ProductSyncLog;

class ProductSyncLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shop = $this->relationLoaded('channelShop') ? $this->channelShop : null;
        $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;
        $channelCode = $channel->code ?? '';

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name ?? null),
            'sku' => $this->whenLoaded('product', fn () => $this->product->sku ?? null),
            'channel_shop_id' => $this->channel_shop_id,
            'shop_name' => $shop->shop_name ?? null,
            'channel_name' => $channel->name ?? null,
            'action' => $this->action,
            'status' => $this->status,
            'error_message' => $this->humanReadableError($channelCode),
            'error' => $this->errorDetail($channelCode),
            'response' => $this->response,
            'created_at' => $this->created_at,
        ];
    }

    protected function humanReadableError(string $channelCode): ?string
    {
        if ($this->status !== ProductSyncLog::STATUS_FAILED || empty($this->error_message)) {
            return $this->error_message;
        }

        return UploadErrorPresenter::fromMessage($channelCode, (string) $this->error_message)['reason'];
    }

    protected function errorDetail(string $channelCode): ?array
    {
        if ($this->status !== ProductSyncLog::STATUS_FAILED || empty($this->error_message)) {
            return null;
        }

        return UploadErrorPresenter::fromMessage($channelCode, (string) $this->error_message);
    }
}
