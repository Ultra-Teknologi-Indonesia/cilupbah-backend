<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReplenishmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reasonDetail = [
            'type' => 'stock_shortage',
            'label' => 'Kekurangan stok dari pesanan aktif',
            'demand_qty' => (int) $this->demand_qty,
            'available_qty' => (int) $this->available_qty,
            'in_flight_qty' => (int) $this->in_flight_qty,
            'suggested_qty' => (int) $this->suggested_qty,
        ];

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'sku' => $this->sku,
            'product_name' => $this->variant?->product?->name,
            'thumbnail_url' => $this->resolveThumbnail($this->variant),
            'qty' => (int) $this->qty,
            'demand_qty' => (int) $this->demand_qty,
            'available_qty' => (int) $this->available_qty,
            'in_flight_qty' => (int) $this->in_flight_qty,
            'suggested_qty' => (int) $this->suggested_qty,
            'reason' => $this->reason,
            'reason_detail' => $reasonDetail,
        ];
    }

    private function resolveThumbnail($variant): ?string
    {
        if (! $variant) {
            return null;
        }

        $variantMedia = $variant->media?->firstWhere('media_type', 'image')
            ?? $variant->media?->first();
        if ($variantMedia?->url) {
            return $variantMedia->url;
        }

        $productMedia = $variant->product?->media?->firstWhere('media_type', 'image')
            ?? $variant->product?->media?->first();

        return $productMedia?->url;
    }
}
